<?php
namespace Intersect\LogicAssistant;

/**
 * Builds a compact, plain-text description of a project's structure for the model:
 * events and arms, instruments (and where they are designated/repeating), and fields.
 *
 * Only METADATA is included - never record data.
 *
 * If the full description exceeds the character budget, field detail is reduced in stages
 * (drop field notes/labels on lengthy choice lists, then drop choice labels, then drop fields
 * from instruments the user did not mention) so the request still fits.
 */
class ProjectContext
{
	const DEFAULT_MAX_CHARS = 60000;
	const MAX_LABEL_CHARS = 120;

	private $project;
	private $draftMode;
	private $metadata;
	private $forms;
	private $uniqueEventNames = [];

	public function __construct(int $projectId)
	{
		$this->project = new \Project($projectId);
		// In production + draft mode, designers are usually writing logic against the draft fields
		$this->draftMode = $this->project->project['status'] > 0 && $this->project->project['draft_mode'] > 0;
		if ($this->draftMode && $this->project->metadata_temp === null) {
			$this->project->loadMetadataTemp(); // not loaded by the Project constructor
		}
		$this->metadata = $this->draftMode ? $this->project->metadata_temp : $this->project->metadata;
		$this->forms = $this->draftMode ? $this->project->forms_temp : $this->project->forms;
		if ($this->project->longitudinal) {
			$this->uniqueEventNames = $this->project->getUniqueEventNames();
		}
	}

	public function isLongitudinal(): bool
	{
		return (bool)$this->project->longitudinal;
	}

	public function getFieldNames(): array
	{
		return array_keys($this->metadata);
	}

	public function getField(string $field): ?array
	{
		return $this->metadata[$field] ?? null;
	}

	public function getUniqueEventNames(): array
	{
		return array_values($this->uniqueEventNames);
	}

	/**
	 * @param string $userRequest Used to prioritise instruments/fields the user mentioned when trimming.
	 */
	public function build(string $userRequest, int $maxChars = self::DEFAULT_MAX_CHARS): string
	{
		// Progressively cheaper renderings of the field list until it fits
		foreach (['full', 'no_choice_labels', 'mentioned_forms_only'] as $level) {
			$text = $this->render($level, $userRequest);
			if (mb_strlen($text) <= $maxChars) return $text;
		}
		// Last resort: hard cut, with a note so the model knows the list is incomplete
		return mb_substr($text, 0, $maxChars) . "\n[... field list truncated to fit the request size limit ...]\n";
	}

	private function render(string $level, string $userRequest): string
	{
		$out = [];
		$out[] = '## Project structure';
		$out[] = 'Record ID field: ' . $this->project->table_pk;
		$out[] = 'Longitudinal: ' . ($this->isLongitudinal() ? 'yes' : 'no');
		if ($this->draftMode) $out[] = 'The project is in Draft Mode; the fields below are the draft versions.';

		$repeating = $this->project->getRepeatingFormsEvents(); // [event_id => 'WHOLE' | [form => label]]

		// Events
		if ($this->isLongitudinal()) {
			$multiArm = count($this->project->events) > 1;
			$out[] = '';
			$out[] = '## Events (unique event name | label' . ($multiArm ? ' | arm' : '') . ' | instruments designated | repeating?)';
			foreach ($this->project->eventInfo as $eventId => $info) {
				$forms = $this->project->eventsForms[$eventId] ?? [];
				$isRepeatingEvent = isset($repeating[$eventId]) && !is_array($repeating[$eventId]);
				$out[] = '- ' . ($this->uniqueEventNames[$eventId] ?? $eventId)
					. ' | ' . $this->clean($info['name'])
					. ($multiArm ? ' | arm ' . $info['arm_num'] . ' (' . $this->clean($info['arm_name']) . ')' : '')
					. ' | ' . implode(', ', $forms)
					. ' | ' . ($isRepeatingEvent ? 'REPEATING EVENT' : 'no');
			}
		}

		// Instruments
		$out[] = '';
		$out[] = '## Instruments (form name | label | repeating?)';
		$mentionedForms = [];
		foreach ($this->forms as $form => $formInfo) {
			$repeatingIn = [];
			foreach ($repeating as $eventId => $value) {
				if (is_array($value) && array_key_exists($form, $value)) {
					$repeatingIn[] = $this->isLongitudinal() ? ($this->uniqueEventNames[$eventId] ?? $eventId) : 'yes';
				}
			}
			$repeatText = empty($repeatingIn) ? 'no' : ($this->isLongitudinal() ? 'REPEATING INSTRUMENT in ' . implode(', ', $repeatingIn) : 'REPEATING INSTRUMENT');
			$label = $this->clean($formInfo['menu'] ?? $form);
			$out[] = "- $form | $label | $repeatText";
			if (stripos($userRequest, $form) !== false || ($label !== '' && stripos($userRequest, $label) !== false)) {
				$mentionedForms[$form] = true;
			}
		}

		// Fields, grouped by instrument
		$out[] = '';
		$out[] = '## Fields by instrument (variable | type[, validation] | label | choices as code=label)';
		foreach ($this->forms as $form => $formInfo) {
			if ($level === 'mentioned_forms_only' && !empty($mentionedForms) && !isset($mentionedForms[$form])) {
				$out[] = "### $form (fields omitted for length: " . implode(', ', array_keys($formInfo['fields'] ?? [])) . ')';
				continue;
			}
			$out[] = "### $form";
			foreach (array_keys($formInfo['fields'] ?? []) as $field) {
				$attr = $this->metadata[$field] ?? null;
				if ($attr === null) continue;
				$out[] = $this->renderField($field, $attr, $level);
			}
		}

		return implode("\n", $out) . "\n";
	}

	private function renderField(string $field, array $attr, string $level): string
	{
		$type = $attr['element_type'];
		// Map REDCap's internal type names to the names designers know
		$typeNames = ['select' => 'dropdown', 'textarea' => 'notes', 'calc' => 'calc', 'advcheckbox' => 'checkbox'];
		$typeName = $typeNames[$type] ?? $type;
		if (($attr['element_validation_type'] ?? '') !== '' && $type === 'text') {
			$typeName .= ', ' . $attr['element_validation_type'];
		}
		if ($type === 'text' && strpos((string)$attr['misc'], '@CALCTEXT') !== false) $typeName .= ', @CALCTEXT';
		if ($type === 'text' && strpos((string)$attr['misc'], '@CALCDATE') !== false) $typeName .= ', @CALCDATE';

		$line = "- $field | $typeName | " . $this->clean($attr['element_label']);

		if (in_array($type, ['select', 'radio', 'checkbox', 'advcheckbox', 'yesno', 'truefalse'], true)) {
			$choices = parseEnum((string)$attr['element_enum']);
			if ($level === 'full') {
				$parts = [];
				foreach ($choices as $code => $label) $parts[] = $code . '=' . $this->clean($label, 40);
				$line .= ' | ' . implode('; ', $parts);
			} else {
				$line .= ' | codes: ' . implode(',', array_keys($choices));
			}
		} elseif ($type === 'slider') {
			$line .= ' | numeric 0-100 unless @MIN/@MAX set';
		}
		return $line;
	}

	private function clean($text, int $max = self::MAX_LABEL_CHARS): string
	{
		$text = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string)$text, ENT_QUOTES))));
		return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
	}
}
