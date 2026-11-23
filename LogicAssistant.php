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

		$tokenBudget = $this->remainingTokenBudget($projectId); // null = no limit; throws if a limit is already reached

		$maxChars = (int)$this->getSystemSetting('max-context-chars');
		if ($maxChars <= 0) $maxChars = ProjectContext::DEFAULT_MAX_CHARS;

		$projectContext = new ProjectContext($projectId);
		$validator = new LogicValidator($projectContext, $projectId);
		$client = new AIClient();

		$guidance = (string)$this->getProjectSetting('project-guidance');
		$systemPrompt = PromptBuilder::systemPrompt($context);

		$result = null;
		$check = ['errors' => [], 'warnings' => []];
		$attemptsMade = 0;
		$tokens = 0;
		$routing = 'none';
		$succeeded = false;
		try {
			$description = $this->describeProject($projectContext, $client, $context, $request, $existingLogic, $guidance, $location, $maxChars);
			$tokens += $description['tokens'];
			$routing = $description['routing'];
			$userMessage = PromptBuilder::userMessage($description['text'], $request, $existingLogic, $guidance, $location);
			$message = $userMessage;

			for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
				// Don't start a correction attempt once a daily limit is reached; show what we have
				if ($attempt > 1 && $tokenBudget !== null && $tokens >= $tokenBudget) {
					$check['warnings'][] = 'No further corrections were attempted because the daily usage limit for the Logic Assistant has been reached.';
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
				'routing' => $routing,
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
	 * The project description to send with the request.
	 *
	 * Most projects fit within the size limit whole, and nothing more is needed. For a project that doesn't, the AI
	 * is first asked which instruments the request needs (sending only the instruments and their field names), and
	 * those are described in full. Instruments named in the request or the existing logic, and the instrument open
	 * in the Online Designer, are always included. If the AI can't choose, the older trimming is used instead.
	 *
	 * @return array{text: string, routing: string, tokens: int} routing: 'none' (whole project sent), 'ai' or 'fallback'
	 */
	private function describeProject(ProjectContext $projectContext, AIClient $client, string $context, string $request,
									 string $existingLogic, string $guidance, array $location, int $maxChars): array
	{
		$whole = $projectContext->buildWhole($maxChars);
		if ($whole !== null) return ['text' => $whole, 'routing' => 'none', 'tokens' => 0];

		$forms = $projectContext->formsNamedIn($request . "\n" . $existingLogic);
		if ($location['instrument'] !== '' && $projectContext->hasForm($location['instrument'])) {
			$forms[] = $location['instrument'];
		}

		$tokens = 0;
		try {
			$reply = $client->complete(
				PromptBuilder::routingSystemPrompt($context),
				PromptBuilder::routingUserMessage($projectContext->instrumentIndex($maxChars), $request, $existingLogic, $guidance, $location)
			);
			$tokens = $reply['tokens'];
			$chosen = $this->extractJson($reply['text'])['instruments'] ?? null;
			$chosen = is_array($chosen) ? array_filter($chosen, function ($form) use ($projectContext) {
				return is_string($form) && $projectContext->hasForm($form);
			}) : [];
			if (!empty($chosen)) {
				return [
					'text' => $projectContext->buildForForms(array_unique(array_merge($forms, $chosen)), $maxChars),
					'routing' => 'ai',
					'tokens' => $tokens,
				];
			}
		} catch (UserFacingException $e) {
			// The AI service failed or replied with nothing usable; fall back below. If the service is down,
			// the main request will fail too and report the error.
		}
		$text = empty($forms) ? $projectContext->build($request, $maxChars) : $projectContext->buildForForms($forms, $maxChars);
		return ['text' => $text, 'routing' => 'fallback', 'tokens' => $tokens];
	}

	/**
	 * Models occasionally wrap JSON in code fences or add stray text; extract the outermost object.
	 */
	private function extractJson(string $text): ?array
	{
		$text = trim($text);
		$text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
		$start = strpos($text, '{');
		$end = strrpos($text, '}');
		$decoded = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
		return is_array($decoded) ? $decoded : null;
	}

	private function parseModelJson(string $text): array
	{
		$decoded = $this->extractJson($text);
		if ($decoded === null) {
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
	 * Tokens still available today under the project's limit and the site-wide limit, whichever is lower,
	 * or null if neither limit is set. Throws if either limit has already been reached.
	 */
	private function remainingTokenBudget(int $projectId): ?int
	{
		$remaining = null;
		$projectLimit = $this->getProjectTokenLimit();
		if ($projectLimit > 0) {
			$left = $projectLimit - $this->getTokensUsedToday($projectId);
			if ($left <= 0) {
				throw new UserFacingException('This project has reached its daily usage limit for the Logic Assistant. '
					. 'The limit resets at midnight (server time). Contact your REDCap administrator if you need it raised.');
			}
			$remaining = $left;
		}
		$siteLimit = $this->parseLimit($this->getSystemSetting('site-daily-token-limit')) ?? 0;
		if ($siteLimit > 0) {
			$left = $siteLimit - $this->getTokensUsedToday(null);
			if ($left <= 0) {
				throw new UserFacingException('The Logic Assistant has reached its daily usage limit for this REDCap site. '
					. 'The limit resets at midnight (server time). Contact your REDCap administrator if you need it raised.');
			}
			$remaining = $remaining === null ? $left : min($remaining, $left);
		}
		return $remaining;
	}

	/**
	 * The daily token limit in effect for the current project: the project override if a super user
	 * has set one, otherwise the system setting. 0 means no limit.
	 */
	private function getProjectTokenLimit(): int
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

	/**
	 * Tokens used since midnight, server time, from the module's own log: by one project, or across the
	 * whole site if $projectId is null. (Naming project_id in the query stops the framework limiting it
	 * to the current project.)
	 */
	private function getTokensUsedToday(?int $projectId): int
	{
		$today = date('Y-m-d 00:00:00');
		$result = $projectId === null
			? $this->queryLogs('select sum(tokens) as total where message = ? and project_id is not null and timestamp >= ?',
				[self::LOG_MESSAGE, $today])
			: $this->queryLogs('select sum(tokens) as total where message = ? and project_id = ? and timestamp >= ?',
				[self::LOG_MESSAGE, $projectId, $today]);
		return (int)($result->fetch_assoc()['total'] ?? 0);
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
