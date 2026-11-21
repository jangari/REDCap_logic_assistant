<?php
namespace Intersect\LogicAssistant;

/**
 * Checks a suggested logic string before it is shown to the user:
 *  1. Every [bracketed] reference is a real field, unique event name, Smart Variable or instance number,
 *     and any checkbox code used, e.g. [symptoms(3)], is one of that field's codes.
 *  2. The logic parses with REDCap's own logic parser (after Smart Variables are piped, as core does).
 *
 * Problems found are returned as plain-language messages that can be sent straight back
 * to the model for a corrected attempt.
 */
class LogicValidator
{
	private $context;
	private $projectId;
	private $smartVariables = null;

	public function __construct(ProjectContext $context, int $projectId)
	{
		$this->context = $context;
		$this->projectId = $projectId;
	}

	/**
	 * @return array ['errors' => string[], 'warnings' => string[]]
	 */
	public function validate(string $logic): array
	{
		$errors = [];
		$warnings = [];

		if (trim($logic) === '') {
			return ['errors' => ['The suggested logic is empty.'], 'warnings' => []];
		}

		$errors = array_merge($errors, $this->checkReferences($logic, $warnings));

		$syntax = $this->checkSyntax($logic);
		if ($syntax === false) {
			$errors[] = 'The logic does not parse as valid REDCap logic syntax (check brackets, quotes, operators and function names).';
		} elseif ($syntax === null) {
			$warnings[] = 'Syntax could not be checked automatically on this REDCap version; please test the logic.';
		}

		return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
	}

	private function checkReferences(string $logic, array &$warnings): array
	{
		$errors = [];
		// Ignore anything inside quoted strings, e.g. [field] = "[not a reference]"
		$unquoted = preg_replace('/"[^"]*"|\'[^\']*\'/', '""', $logic);

		$fields = array_flip($this->context->getFieldNames());
		$events = array_flip($this->context->getUniqueEventNames());
		$smartVars = $this->getSmartVariableNames();

		preg_match_all('/\[([^\[\]]+)\]/', $unquoted, $matches);
		foreach (array_unique($matches[1]) as $token) {
			$token = trim($token);
			if ($token === '' || ctype_digit($token)) continue; // instance number, e.g. [visit_date][2]

			// Split off a checkbox code "(3)" and any ":parameter" suffix, e.g. [dob:value], [event-name:label]
			$checkboxCode = null;
			if (preg_match('/^([^(:]+)\(([^)]*)\)/', $token, $m)) {
				$name = $m[1];
				$checkboxCode = $m[2];
			} else {
				$name = explode(':', $token, 2)[0];
			}
			$name = trim($name);

			if (isset($smartVars[strtolower($name)])) continue;
			if (isset($events[$name])) continue;

			if (isset($fields[$name])) {
				if ($checkboxCode !== null) {
					$field = $this->context->getField($name);
					if (!in_array($field['element_type'], ['checkbox', 'advcheckbox'], true)) {
						$errors[] = "[$name] is not a checkbox field, so it cannot be written as [$name($checkboxCode)].";
					} elseif (!array_key_exists($checkboxCode, parseEnum((string)$field['element_enum']))
							&& !in_array($checkboxCode, ['', '*'], true)) {
						$errors[] = "\"$checkboxCode\" is not a choice code of checkbox field [$name].";
					}
				}
				continue;
			}

			$errors[] = "[$token] is not a field, unique event name or Smart Variable in this project.";
		}

		return $errors;
	}

	/**
	 * @return bool|null true = valid, false = invalid, null = could not check
	 */
	private function checkSyntax(string $logic): ?bool
	{
		if (!class_exists('\LogicTester') || !method_exists('\LogicTester', 'isValid')) return null;
		try {
			// Pipe Smart Variables first, the same way REDCap's own logic validation does
			if (class_exists('\Piping') && method_exists('\Piping', 'pipeSpecialTags')) {
				$user = defined('USERID') ? USERID : null;
				$logic = \Piping::pipeSpecialTags($logic, $this->projectId, null, null, null, $user, true, null, null, false, false, false, true);
			}
			return (bool)\LogicTester::isValid($logic);
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function getSmartVariableNames(): array
	{
		if ($this->smartVariables !== null) return $this->smartVariables;
		$this->smartVariables = [];
		if (class_exists('\Piping') && method_exists('\Piping', 'getSpecialTags')) {
			try {
				foreach (\Piping::getSpecialTags() as $tag) {
					$this->smartVariables[strtolower(explode(':', $tag, 2)[0])] = true;
				}
			} catch (\Throwable $e) {
				// leave empty; references will be checked against fields/events only
			}
		}
		return $this->smartVariables;
	}
}
