<?php
namespace Intersect\LogicAssistant;

use ExternalModules\AbstractExternalModule;

require_once __DIR__ . '/classes/UserFacingException.php';
require_once __DIR__ . '/classes/AIClient.php';
require_once __DIR__ . '/classes/ProjectContext.php';
require_once __DIR__ . '/classes/LogicValidator.php';
require_once __DIR__ . '/classes/PromptBuilder.php';

class LogicAssistant extends AbstractExternalModule
{
	const MAX_REQUEST_CHARS = 2000;
	const MAX_EXISTING_LOGIC_CHARS = 10000;
	const MAX_ATTEMPTS = 3; // first attempt + up to 2 corrections after validation failures
	const LOG_MESSAGE = 'Logic suggestion';

	/* ===================== Hooks ===================== */

	public function redcap_every_page_top($project_id)
	{
		if (empty($project_id) || !$this->isAuthenticated() || $this->isSurveyPage()) return;
		if (!$this->userMayUse($project_id)) return;
		if (!(new AIClient())->isAvailable()) return;

		$this->initializeJavascriptModuleObject();
		$jsmo = $this->getJavascriptModuleObjectName();
		$config = [
			'contexts' => PromptBuilder::contextLabels(),
			'username' => defined('USERID') ? USERID : '', // the disclaimer acceptance cookie is per user
		];
		?>
		<link rel="stylesheet" href="<?= $this->getUrl('css/logic_assistant.css') ?>">
		<script src="<?= $this->getUrl('js/logic_assistant.js') ?>"></script>
		<script>
			(function () {
				if (window.IntersectLogicAssistant) {
					window.IntersectLogicAssistant.init(<?= $jsmo ?>, <?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
				}
			})();
		</script>
		<?php
	}

	public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
									   $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
	{
		if ($action !== 'suggest') {
			return ['error' => 'Unknown action.'];
		}
		if (empty($project_id) || !$this->userMayUse($project_id)) {
			return ['error' => 'You do not have permission to use the Logic Assistant in this project.'];
		}
		try {
			return $this->suggest((int)$project_id, is_array($payload) ? $payload : []);
		} catch (UserFacingException $e) {
			return ['error' => $e->getMessage()];
		} catch (\Throwable $e) {
			// Anything else may contain internal detail (SQL, paths, core errors): log it, don't show it
			error_log('Logic Assistant (project ' . (int)$project_id . '): ' . get_class($e) . ': ' . $e->getMessage()
				. ' in ' . $e->getFile() . ':' . $e->getLine());
			return ['error' => 'Something went wrong while generating a suggestion. Please try again, or contact your REDCap administrator if this keeps happening.'];
		}
	}

	/* ===================== Core flow ===================== */

	private function suggest(int $projectId, array $payload): array
	{
		$request = trim($this->stringParam($payload, 'request'));
		if ($request === '') return ['error' => 'Please describe the logic you need.'];
		if (mb_strlen($request) > self::MAX_REQUEST_CHARS) {
			return ['error' => 'Please shorten your description to ' . self::MAX_REQUEST_CHARS . ' characters or fewer.'];
		}
		$existingLogic = mb_substr($this->stringParam($payload, 'existing_logic'), 0, self::MAX_EXISTING_LOGIC_CHARS);
		$context = PromptBuilder::normaliseContext($payload['context'] ?? null);
		$location = [
			'instrument' => $this->cleanName($this->stringParam($payload, 'instrument')),
			'field' => $this->cleanName($this->stringParam($payload, 'field')),
			'event' => $this->cleanName($this->stringParam($payload, 'event')),
		];

		$tokenLimit = $this->getDailyTokenLimit();
		$tokensUsedToday = $tokenLimit > 0 ? $this->getTokensUsedToday($projectId) : 0;
		if ($tokenLimit > 0 && $tokensUsedToday >= $tokenLimit) {
			throw new UserFacingException('This project has reached its daily usage limit for the Logic Assistant. '
				. 'The limit resets at midnight (server time). Contact your REDCap administrator if you need it raised.');
		}

		$maxChars = (int)$this->getSystemSetting('max-context-chars');
		if ($maxChars <= 0) $maxChars = ProjectContext::DEFAULT_MAX_CHARS;

		$projectContext = new ProjectContext($projectId);
		$validator = new LogicValidator($projectContext, $projectId);
		$client = new AIClient();

		$systemPrompt = PromptBuilder::systemPrompt($context);
		$userMessage = PromptBuilder::userMessage(
			$projectContext->build($request, $maxChars),
			$request,
			$existingLogic,
			(string)$this->getProjectSetting('project-guidance'),
			$location
		);

		$message = $userMessage;
		$result = null;
		$check = ['errors' => [], 'warnings' => []];
		$attemptsMade = 0;
		$tokens = 0;
		$succeeded = false;
		try {
			for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
				// Don't start a correction attempt once the project is over its daily limit; show what we have
				if ($attempt > 1 && $tokenLimit > 0 && $tokensUsedToday + $tokens >= $tokenLimit) {
					$check['warnings'][] = 'No further corrections were attempted because this project has reached its daily usage limit for the Logic Assistant.';
					break;
				}
				$attemptsMade = $attempt;
				$reply = $client->complete($systemPrompt, $message);
				$tokens += $reply['tokens'];
				$result = $this->parseModelJson($reply['text']);

				// The model asked a clarifying question instead of answering: nothing to validate
				if ($result['logic'] === '') {
					$check = ['errors' => [], 'warnings' => []];
					break;
				}
				$check = $validator->validate($result['logic']);
				if (empty($check['errors'])) break;
				$message = PromptBuilder::retryMessage($userMessage, $result['logic'], $check['errors']);
			}
			$succeeded = true;
		} finally {
			// Record usage, including failed requests, since tokens may still have been spent.
			// No request text or logic is logged, only what kind of request it was and how it went.
			$this->log(self::LOG_MESSAGE, [
				'context' => $context,
				'attempts' => $attemptsMade,
				'valid' => ($succeeded && empty($check['errors'])) ? 1 : 0,
				'outcome' => $succeeded ? 'ok' : 'error',
				'tokens' => $tokens,
				'ai_service' => $client->getServiceName(),
			]);
		}

		return [
			'logic' => $result['logic'],
			'explanation' => $result['explanation'],
			'assumptions' => $result['assumptions'],
			'clarification' => $result['clarification'],
			'errors' => $check['errors'],
			'warnings' => $check['warnings'],
		];
	}

	/**
	 * Models occasionally wrap JSON in code fences or add stray text; extract the outermost object.
	 */
	private function parseModelJson(string $text): array
	{
		$text = trim($text);
		$text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
		$start = strpos($text, '{');
		$end = strrpos($text, '}');
		$decoded = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
		if (!is_array($decoded)) {
			throw new UserFacingException('The AI service returned a response that could not be read. Please try again.');
		}
		foreach (['logic', 'explanation', 'clarification'] as $key) {
			if (isset($decoded[$key]) && !is_scalar($decoded[$key])) $decoded[$key] = '';
		}
		$assumptions = $decoded['assumptions'] ?? [];
		if (!is_array($assumptions)) $assumptions = [$assumptions];
		$assumptions = array_filter($assumptions, 'is_scalar');
		return [
			'logic' => trim((string)($decoded['logic'] ?? '')),
			'explanation' => trim((string)($decoded['explanation'] ?? '')),
			'assumptions' => array_values(array_filter(array_map('strval', $assumptions), 'strlen')),
			'clarification' => trim((string)($decoded['clarification'] ?? '')),
		];
	}

	/* ===================== Helpers ===================== */

	private function userMayUse($projectId): bool
	{
		if (!$this->getSystemSetting('require-design-rights')) return true;
		try {
			$user = $this->getUser();
			return $user->isSuperUser() || $user->hasDesignRights($projectId);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * The daily token limit in effect for the current project: the project override if a super user
	 * has set one, otherwise the system setting. 0 means no limit.
	 */
	private function getDailyTokenLimit(): int
	{
		$override = $this->parseLimit($this->getProjectSetting('project-daily-token-limit'));
		if ($override !== null) return $override;
		return $this->parseLimit($this->getSystemSetting('daily-token-limit')) ?? 0;
	}

	/** A non-negative whole number, or null if the setting is blank or not a number. */
	private function parseLimit($value): ?int
	{
		$value = is_scalar($value) ? trim((string)$value) : '';
		return ctype_digit($value) ? (int)$value : null;
	}

	/** Tokens used by the project since midnight, server time, from the module's own log. */
	private function getTokensUsedToday(int $projectId): int
	{
		$result = $this->queryLogs(
			'select tokens where message = ? and project_id = ? and timestamp >= ?',
			[self::LOG_MESSAGE, $projectId, date('Y-m-d 00:00:00')]
		);
		$total = 0;
		while ($row = $result->fetch_assoc()) {
			$total += (int)$row['tokens'];
		}
		return $total;
	}

	/** A string value from the browser payload; anything else (arrays, objects) is treated as blank. */
	private function stringParam(array $payload, string $key): string
	{
		$value = $payload[$key] ?? '';
		return is_scalar($value) ? (string)$value : '';
	}

	/** Allow only REDCap variable/form/event-name characters from the browser. */
	private function cleanName($value): string
	{
		return preg_replace('/[^a-z0-9_]/', '', strtolower((string)$value));
	}
}
