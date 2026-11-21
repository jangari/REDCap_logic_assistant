<?php
namespace Intersect\LogicAssistant;

/**
 * Thin adapter between the module and the GenAI service REDCap has configured for the current project
 * (Control Center > GenAI Settings, and the project's own choice under Edit a Project's Settings).
 * The module deliberately has no AI endpoint configuration of its own: it augments whatever GenAI
 * service the project already uses.
 *
 * NOTE: REDCap's AI classes are NOT part of the documented External Module framework API, so they
 * may change between REDCap versions. Every touch-point with them lives in this file, guarded by
 * class_exists/method_exists checks, so that a change in core only needs fixing in one place.
 */
class AIClient
{
	const SERVICE_OPENAI = 1; // Azure OpenAI or an OpenAI-compatible endpoint
	const SERVICE_GEMINI = 2;
	const MAX_OUTPUT_TOKENS = 4096;
	const AI_LOG_CALL_TYPE = 'LogicAssistant'; // the "type" recorded in core's redcap_ai_log

	/**
	 * Whether a GenAI service is configured and enabled for the current project.
	 * Honours the project's own GenAI choice (a project with GenAI disabled returns false).
	 */
	public function isAvailable(): bool
	{
		// Core resolves the project's GenAI choice from the PROJECT_ID constant. Without it, core would fall
		// back to the system-level configuration and ignore a project that has GenAI switched off.
		if (!defined('PROJECT_ID')) return false;
		if (!class_exists('\AI') || !method_exists('\AI', 'isServiceDetailsSet')) return false;
		try {
			return (bool)\AI::isServiceDetailsSet();
		} catch (\Throwable $e) {
			return false;
		}
	}

	/** Short name of the configured service type, for logging. */
	public function getServiceName(): string
	{
		if (!$this->isAvailable()) return '';
		$type = \AI::getServiceAttributes()['service_enabled'] ?? null;
		return $type == self::SERVICE_OPENAI ? 'openai' : ($type == self::SERVICE_GEMINI ? 'gemini' : '');
	}

	/**
	 * Send a system prompt + user message and return the model's text reply and the tokens it used.
	 * Token counts come from the service's usage report; if a service doesn't report usage, they are
	 * estimated from the text length (about 4 characters per token).
	 *
	 * @return array{text: string, tokens: int}
	 * @throws UserFacingException with a user-presentable message on failure.
	 */
	public function complete(string $systemPrompt, string $userMessage): array
	{
		if (!$this->isAvailable()) {
			throw new UserFacingException('GenAI is not configured or enabled for this project.');
		}
		$attr = \AI::getServiceAttributes();
		switch ($attr['service_enabled'] ?? null) {
			case self::SERVICE_OPENAI:
				$reply = $this->completeOpenAI($attr['service_details'], $systemPrompt, $userMessage);
				$this->logToCore('serviceAzureOpenAI', 'AzureOpenAI', $systemPrompt, $userMessage, $reply['text']);
				break;
			case self::SERVICE_GEMINI:
				$reply = $this->completeGemini($attr['service_details'], $systemPrompt, $userMessage);
				$this->logToCore('serviceGemini', 'Gemini', $systemPrompt, $userMessage, $reply['text']);
				break;
			default: throw new UserFacingException('The GenAI service configured for this project is not supported by Logic Assistant.');
		}
		if ($reply['tokens'] <= 0) {
			$reply['tokens'] = (int)ceil(mb_strlen($systemPrompt . $userMessage . $reply['text']) / 4);
		}
		return $reply;
	}

	/**
	 * Record the call in core's redcap_ai_log, as REDCap's own AI features do, so it shows up in
	 * REDCap's AI usage statistics. Core stores only character and word counts, never the text.
	 * Logging must never break a suggestion, so any failure here is ignored.
	 */
	private function logToCore(string $serviceProperty, string $serviceFallback, string $system, string $user, string $response): void
	{
		try {
			if (!method_exists('\AI', 'logApiCall') || !defined('UI_ID') || !defined('PROJECT_ID')) return;
			$service = property_exists('\AI', $serviceProperty) ? \AI::${$serviceProperty} : $serviceFallback;
			\AI::logApiCall($service, self::AI_LOG_CALL_TYPE, $system . "\n\n" . $user, $response, PROJECT_ID);
		} catch (\Throwable $e) {
			// ignore
		}
	}

	/* ---------- Azure OpenAI / OpenAI-compatible ---------- */

	/** @return array{text: string, tokens: int} */
	private function completeOpenAI(array $details, string $system, string $user): array
	{
		$class = '\Vanderbilt\REDCap\Classes\OpenAI\OpenAi';
		if (!class_exists($class)) throw new UserFacingException('REDCap\'s OpenAI client class could not be found.');

		// Core stores the deployment/model name in the "api version" slot (it sends it as "model").
		[$endpointUrl, $apiKey, $apiVersion] = $details;
		$client = new $class($apiKey, $endpointUrl);
		$raw = $client->chat([
			'model' => $apiVersion,
			'messages' => [
				['role' => 'system', 'content' => $system],
				['role' => 'user', 'content' => $user],
			],
			'max_completion_tokens' => self::MAX_OUTPUT_TOKENS,
		], $apiVersion);

		$decoded = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($decoded)) {
			throw new UserFacingException('No response was returned from the AI service.');
		}
		if (isset($decoded['error'])) {
			$err = $decoded['error'];
			$msg = is_array($err) ? (($err['code'] ?? '') . ': ' . ($err['message'] ?? 'unknown error')) : (string)$err;
			throw new UserFacingException('AI service error ' . $msg);
		}
		// Deliberately NOT passed through filter_tags(): logic such as "[a] < 5 and [b] > 2" looks like an HTML tag.
		$text = $decoded['choices'][0]['message']['content'] ?? '';
		if (trim((string)$text) === '') throw new UserFacingException('The AI service returned an empty response.');
		return ['text' => (string)$text, 'tokens' => (int)($decoded['usage']['total_tokens'] ?? 0)];
	}

	/* ---------- Google Gemini ---------- */

	/** @return array{text: string, tokens: int} */
	private function completeGemini(array $details, string $system, string $user): array
	{
		$class = '\Vanderbilt\REDCap\Classes\GeminiAI\GeminiAi';
		if (!class_exists($class)) throw new UserFacingException('REDCap\'s Gemini client class could not be found.');

		[$apiKey, $apiModel, $apiVersion] = $details;
		$client = new $class($apiKey, $apiModel, $apiVersion);
		$raw = $client->chat([
			'systemInstruction' => ['parts' => [['text' => $system]]],
			'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
			'generationConfig' => [
				'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
				'responseMimeType' => 'application/json',
			],
		]);

		$decoded = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($decoded)) {
			throw new UserFacingException('No response was returned from the Gemini service.');
		}
		if (isset($decoded['error'])) {
			throw new UserFacingException('Gemini error ' . ($decoded['error']['code'] ?? '') . ': ' . ($decoded['error']['message'] ?? 'unknown error'));
		}
		$text = '';
		foreach ($decoded['candidates'][0]['content']['parts'] ?? [] as $part) {
			$text .= $part['text'] ?? '';
		}
		if (trim($text) === '') throw new UserFacingException('The Gemini service returned an empty response.');
		return ['text' => $text, 'tokens' => (int)($decoded['usageMetadata']['totalTokenCount'] ?? 0)];
	}
}
