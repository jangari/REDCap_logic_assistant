<?php
namespace Intersect\LogicAssistant;

/**
 * Builds a compact, plain-text description of a project's structure for the model:
 * events and arms, instruments (and where they are designated/repeating), and fields.
 *
 * Only METADATA is included - never record data.
 *
 * Most projects fit within the character budget whole (dropping choice labels if needed). For larger
 * projects, LogicAssistant asks the AI which instruments a request needs (using instrumentIndex()) and
 * then calls buildForForms(), which gives full field detail for those instruments and only field names
 * for the rest. build() is the fallback when the AI can't be asked: it focuses on the instruments the
 * request names, and as a last resort cuts the description short.
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

	public function hasForm(string $form): bool
	{
		return isset($this->forms[$form]);
	}

	/** The description of the whole project if it fits within $maxChars, otherwise null. */
	public function buildWhole(int $maxChars): ?string
	{
		foreach (['full', 'no_choice_labels'] as $level) {
			$text = $this->render($level, null);
			if (mb_strlen($text) <= $maxChars) return $text;
		}
		return null;
	}

	/**
	 * Full field detail for the given instruments; every other instrument lists only its field names.
	 * @param string[] $forms
	 */
	public function buildForForms(array $forms, int $maxChars): string
	{
		$focus = [];
		foreach ($forms as $form) {
			if ($this->hasForm($form)) $focus[$form] = true;
		}
		foreach (['full', 'no_choice_labels'] as $level) {
			$text = $this->render($level, $focus);
			if (mb_strlen($text) <= $maxChars) return $text;
		}
		return $this->truncate($text, $maxChars);
	}

	/**
	 * Fallback when the AI can't choose the instruments: focus on the instruments the request names
	 * (or whose fields it names); if it names none, send everything and cut it short.
	 */
	public function build(string $userRequest, int $maxChars = self::DEFAULT_MAX_CHARS): string
	{
		$whole = $this->buildWhole($maxChars);
		if ($whole !== null) return $whole;
		$named = $this->formsNamedIn($userRequest);
		if (!empty($named)) return $this->buildForForms($named, $maxChars);
		return $this->truncate($this->render('no_choice_labels', null), $maxChars);
	}

	/**
	 * Instruments whose form name, label or field names appear in the text (e.g. the request, or logic
	 * already in the editor). Names are matched as whole words, so a short form name like "bp" doesn't
	 * match inside other words.
	 * @return string[]
	 */
	public function formsNamedIn(string $text): array
	{
		$forms = [];
		preg_match_all('/[a-z][a-z0-9_]*/i', $text, $m);
		foreach (array_unique(array_map('strtolower', $m[0])) as $word) {
			if (isset($this->forms[$word])) $forms[$word] = true;
			$form = $this->metadata[$word]['form_name'] ?? null;
			if ($form !== null && isset($this->forms[$form])) $forms[$form] = true;
		}
		foreach ($this->forms as $form => $formInfo) {
			$label = $this->clean($formInfo['menu'] ?? '');
			if (mb_strlen($label) >= 4 && stripos($text, $label) !== false) $forms[$form] = true;
		}
		return array_keys($forms);
	}

	/**
	 * A short list of the instruments, for the AI to choose from: name, label and fields.
	 * Detail is dropped in stages (field labels, then field names) to fit within $maxChars.
	 */
	public function instrumentIndex(int $maxChars): string
	{
		foreach (['labels', 'names', 'forms_only'] as $level) {
			$out = [];
			foreach ($this->forms as $form => $formInfo) {
				$fields = array_keys($formInfo['fields'] ?? []);
				$line = "- $form | " . $this->clean($formInfo['menu'] ?? $form) . ' | ' . count($fields) . ' fields';
				if ($level === 'labels') {
					$line .= ': ' . implode('; ', array_map(function ($f) {
						return $f . ' (' . $this->clean($this->metadata[$f]['element_label'] ?? '', 40) . ')';
					}, $fields));
				} elseif ($level === 'names') {
					$line .= ': ' . implode(', ', $fields);
				}
				$out[] = $line;
			}
			$text = implode("\n", $out) . "\n";
			if (mb_strlen($text) <= $maxChars) return $text;
		}
		return $this->truncate($text, $maxChars);
	}

	private function truncate(string $text, int $maxChars): string
	{
		// A note so the model knows the list is incomplete
		return mb_substr($text, 0, $maxChars) . "\n[... project description cut short to fit the request size limit ...]\n";
	}

	/**
	 * @param array|null $focus form => true for the instruments to describe in full; null for all of them.
	 *   The focus instruments come first, so if the description must be cut short, the others are what's lost.
	 */
	private function render(string $level, ?array $focus): string
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
		foreach ($this->forms as $form => $formInfo) {
			$repeatingIn = [];
			foreach ($repeating as $eventId => $value) {
				if (is_array($value) && array_key_exists($form, $value)) {
					$repeatingIn[] = $this->isLongitudinal() ? ($this->uniqueEventNames[$eventId] ?? $eventId) : 'yes';
				}
			}
			$repeatText = empty($repeatingIn) ? 'no' : ($this->isLongitudinal() ? 'REPEATING INSTRUMENT in ' . implode(', ', $repeatingIn) : 'REPEATING INSTRUMENT');
			$out[] = "- $form | " . $this->clean($formInfo['menu'] ?? $form) . " | $repeatText";
		}

		// Fields, grouped by instrument: the focus instruments in full, then the rest by name only
		$out[] = '';
		$out[] = '## Fields by instrument (variable | type[, validation] | label | choices as code=label)';
		foreach ($this->forms as $form => $formInfo) {
			if ($focus !== null && !isset($focus[$form])) continue;
			$out[] = "### $form";
			foreach (array_keys($formInfo['fields'] ?? []) as $field) {
				$attr = $this->metadata[$field] ?? null;
				if ($attr === null) continue;
				$out[] = $this->renderField($field, $attr, $level);
			}
		}
		if ($focus !== null) {
			$out[] = '';
			$out[] = '## Other instruments (field names only, detail omitted for length)';
			foreach ($this->forms as $form => $formInfo) {
				if (isset($focus[$form])) continue;
				$out[] = "- $form: " . implode(', ', array_keys($formInfo['fields'] ?? []));
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
