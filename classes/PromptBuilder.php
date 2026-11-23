<?php
namespace Intersect\LogicAssistant;

/**
 * Builds the system prompt (fixed per logic context, so providers that cache prompt prefixes can reuse it)
 * and the user message (project structure + the user's request).
 */
class PromptBuilder
{
	/** Logic contexts the module knows about: key => [label, rules for the model] */
	const CONTEXTS = [
		'branching' => [
			'Branching logic',
			"You are writing BRANCHING LOGIC for a field. The field is shown when the logic is TRUE and hidden when it is FALSE.\n"
			. "- Fields on the same instrument (and same event/instance) need no prefix.\n"
			. "- In longitudinal projects, a field with no event prefix refers to the CURRENT event; prefix with [unique_event_name] only when another event is needed.\n"
			. "- Do not reference the field that the branching logic belongs to.",
		],
		'calc' => [
			'Calculated field',
			"You are writing the EQUATION of a calculated field (type calc). It must evaluate to a NUMBER.\n"
			. "- Use if(condition, value_if_true, value_if_false) for conditional values; it can be nested.\n"
			. "- Use datediff() for date/time differences. Do not wrap numbers in quotes.\n"
			. "- If the user wants a text or date result, say in the explanation that they need the @CALCTEXT or @CALCDATE action tag on a text field instead, and give the expression for that tag.",
		],
		'action_tag' => [
			'Action tag / @CALCTEXT / @CALCDATE / @IF',
			"You are writing an expression that goes INSIDE an action tag in the Field Annotation, such as @CALCTEXT(...), @CALCDATE(...), or @IF(...).\n"
			. "- Return the complete action tag including the tag name and parentheses, e.g. @CALCTEXT(if([age] >= 18, \"Adult\", \"Child\")).\n"
			. "- @CALCDATE takes (date_field, offset_number, units) where units is y, M, d, h, m or s.",
		],
		'report_filter' => [
			'Report filter',
			"You are writing the ADVANCED FILTER LOGIC of a report. A row is included when the logic is TRUE.\n"
			. "- In longitudinal projects, prefix fields with [unique_event_name] to target a particular event; without a prefix the filter is applied to each event's row.\n"
			. "- For repeating instruments, the filter is evaluated per instance row.",
		],
		'asi' => [
			'Survey auto-invitation (ASI) condition',
			"You are writing the CONDITION LOGIC for a survey Automated Survey Invitation. The invitation is scheduled when the logic becomes TRUE as data are saved.\n"
			. "- In longitudinal projects, prefix fields with [unique_event_name] when they live in a different event from the survey.\n"
			. "- Prefer conditions that become TRUE once (e.g. a completion status or a date being entered) to avoid unintended invitations.",
		],
		'alert' => [
			'Alert trigger condition',
			"You are writing the TRIGGER LOGIC of an Alert & Notification. The alert fires when the logic is TRUE as data are saved (or on the scheduled check).\n"
			. "- In longitudinal projects, prefix fields with [unique_event_name] when they live in a different event.\n"
			. "- For repeating instruments, the logic is evaluated in the context of the instance being saved.",
		],
		'dq' => [
			'Data Quality rule',
			"You are writing the LOGIC of a custom Data Quality rule. The rule flags a DISCREPANCY for every record/event/instance where the logic is TRUE, so write the logic to be TRUE for the PROBLEM, not for valid data.",
		],
		'form_display' => [
			'Form display logic',
			"You are writing FORM DISPLAY LOGIC. The instrument is enabled for data entry when the logic is TRUE and disabled when it is FALSE.\n"
			. "- In longitudinal projects, prefix fields with [unique_event_name] when they live in a different event.",
		],
		'survey_queue' => [
			'Survey queue condition',
			"You are writing the CONDITION LOGIC that adds a survey to a participant's SURVEY QUEUE. The survey appears in the queue when the logic is TRUE.\n"
			. "- In longitudinal projects, prefix fields with [unique_event_name] when they live in a different event.",
		],
		'survey_auto_continue' => [
			'Survey auto-continue condition',
			"You are writing the CONDITION LOGIC for survey AUTO-CONTINUE. When a participant completes this survey and the logic is TRUE, they continue straight to the next survey; when FALSE, they see the survey's normal completion text or redirect.\n"
			. "- The logic is evaluated for the record at the moment this survey is submitted.",
		],
		'auto_schedule' => [
			'Calendar auto-scheduling trigger (arm)',
			"You are writing the TRIGGER LOGIC for calendar AUTO-SCHEDULING on an arm. When this logic becomes TRUE as a record's data are saved, the record is scheduled on the arm, with event dates calculated from the arm's anchor date field.\n"
			. "- The logic is evaluated in the context of whichever event is being saved, so in longitudinal projects ALWAYS prefix fields with [unique_event_name].\n"
			. "- Include a check that the anchor date is not blank if the user mentions one, e.g. [baseline_arm_1][enrol_date] <> \"\".\n"
			. "- Prefer conditions that become TRUE once (e.g. eligibility confirmed and consent given).",
		],
		'pdf_snapshot' => [
			'PDF Snapshot trigger',
			"You are writing the TRIGGER LOGIC for a PDF Snapshot. A snapshot is saved when the logic becomes TRUE as data are saved.\n"
			. "- Prefer conditions that become TRUE once, e.g. a signature field being entered or an instrument being marked Complete ([instrument_complete] = \"2\").\n"
			. "- In longitudinal projects, prefix fields with [unique_event_name] when they live in a different event.",
		],
		'randomisation' => [
			'Randomisation trigger',
			"You are writing the TRIGGER LOGIC for automatic (real-time) RANDOMISATION. The record is randomised as soon as the logic becomes TRUE as data are saved.\n"
			. "- The record must have every stratification field (and the DAG, if stratifying by site) completed before randomisation, so include <> \"\" checks for any the user mentions.\n"
			. "- Include the eligibility and consent conditions the user describes. In longitudinal projects, prefix fields with [unique_event_name].",
		],
		'dashboard_filter' => [
			'Record Status Dashboard filter',
			"You are writing the FILTER LOGIC of a custom Record Status Dashboard. A record (or record/event row) is shown on the dashboard when the logic is TRUE.\n"
			. "- In longitudinal projects, prefix fields with [unique_event_name] to target a particular event.",
		],
		'mycap' => [
			'MyCap participant condition',
			"You are writing the CONDITION LOGIC for MyCap participants. The condition applies to a participant (record) when the logic is TRUE.\n"
			. "- In longitudinal projects, prefix fields with [unique_event_name] when they live in a different event.",
		],
		'generic' => [
			'Other / not sure',
			"You are writing REDCap conditional logic that evaluates to TRUE or FALSE.",
		],
	];

	const SYNTAX_REFERENCE = <<<'TXT'
REDCap logic syntax reference:
- Fields are written in square brackets with the VARIABLE NAME (not the label): [age]
- Comparison operators: =  <>  >  >=  <  <=   (use <> for "not equal"; there is no != and no NOT operator)
- Boolean operators: and  or   with parentheses for grouping.
- Text and blank values go in double quotes: [sex] = "1", [dob] <> "" (blank is ""). Numbers may be unquoted.
- Radio, dropdown, yes/no and true/false fields compare against the CHOICE CODE, never the label: [consent] = "1"
- Checkbox fields: [symptoms(3)] = "1" means option code 3 is ticked, "0" means not ticked.
- Longitudinal: [baseline_arm_1][weight] refers to weight in the baseline_arm_1 event.
- Repeating instances: [visit_date][2] is instance 2; also [field][current-instance], [field][previous-instance], [field][next-instance], [field][first-instance], [field][last-instance].
- Smart Variables include: [event-name], [previous-event-name], [next-event-name], [first-event-name], [last-event-name], [arm-number], [current-instance], [user-name], [user-role-name], [user-dag-name], [record-dag-name], [record-name], [is-survey], [is-form], [instrument-name], [survey-date-completed:instrument], [aggregate-mean:field] (and -min, -max, -sum, -count, -median, -stdev).
- Functions: if(cond, a, b); datediff([d1], [d2], "y"|"M"|"d"|"h"|"m"|"s", returnSignedValue) - use "today" or "now" as a date; sum(), mean(), median(), min(), max(), stdev(); round(x, dp), roundup(x, dp), rounddown(x, dp), abs(), sqrt(), exponent via ^ or exponential(); isnumber(), isinteger(), isblankormissingcode(); contains(), not_contain(), starts_with(), ends_with(); left(), right(), mid(), length(), find(), upper(), lower(), trim(), concat(); year(), month(), day(); log(x, base).
- An instrument's completion status is the field [instrument_name_complete] with codes 0=Incomplete, 1=Unverified, 2=Complete.
TXT;

	public static function contextLabels(): array
	{
		$labels = [];
		foreach (self::CONTEXTS as $key => $ctx) $labels[$key] = $ctx[0];
		return $labels;
	}

	/** @param mixed $context Untrusted value from the browser */
	public static function normaliseContext($context): string
	{
		return (is_string($context) && isset(self::CONTEXTS[$context])) ? $context : 'generic';
	}

	public static function systemPrompt(string $context): string
	{
		$rules = self::CONTEXTS[self::normaliseContext($context)][1];
		$syntax = self::SYNTAX_REFERENCE; // class constants cannot be interpolated in a heredoc
		return <<<TXT
You are an expert REDCap project designer helping a user write logic for their project.

$rules

Use only the fields, events and instruments listed in the project structure provided by the user. Never invent variable names, event names or choice codes. If the request is ambiguous or refers to something that does not exist in the project, give your best attempt and state the ambiguity in "assumptions", or, if no sensible attempt is possible, leave "logic" empty and ask a short question in "clarification".

Prefer the simplest logic that does the job. Explain it briefly in plain English, referring to fields by their labels as well as their variable names.

{$syntax}

Respond with ONLY a JSON object, with no markdown code fences and no other text, in exactly this shape:
{"logic": "<the logic, or empty string>", "explanation": "<1-4 sentences>", "assumptions": ["<assumption>", ...], "clarification": "<question for the user, or empty string>"}
TXT;
	}

	public static function userMessage(string $projectStructure, string $request, string $existingLogic, string $guidance, array $location): string
	{
		$parts = [$projectStructure];
		if (trim($guidance) !== '') {
			$parts[] = "## Project-specific guidance from the project's designers\n" . trim($guidance);
		}
		$where = [];
		if (!empty($location['instrument'])) $where[] = 'instrument: ' . $location['instrument'];
		if (!empty($location['field'])) $where[] = 'field being edited: ' . $location['field'];
		if (!empty($location['event'])) $where[] = 'event: ' . $location['event'];
		if (!empty($where)) {
			$parts[] = "## Where the logic will be used\n" . implode("\n", $where);
		}
		if (trim($existingLogic) !== '') {
			$parts[] = "## Logic currently in the editor (the user may want this modified)\n" . $existingLogic;
		}
		$parts[] = "## Request\n" . trim($request);
		return implode("\n\n", $parts);
	}

	/**
	 * For projects too large to describe whole: ask which instruments the request needs, so that only those are
	 * described in full. Only used on large projects; see LogicAssistant::describeProject().
	 */
	public static function routingSystemPrompt(string $context): string
	{
		$kind = self::CONTEXTS[self::normaliseContext($context)][0];
		return <<<TXT
You help write REDCap logic (kind of logic: $kind). The project is too large to describe in full, so first choose which of its instruments are needed to write the logic the user is asking for. Those instruments will then be described in full: every field's type, label and choice codes.

The user will give you the list of the project's instruments, with their fields, followed by their request.

Include every instrument that holds a field the logic might need, including fields the user describes in their own words rather than by variable name. When unsure, include the instrument: an extra instrument costs little, while a missing one means the logic cannot be written correctly.

Respond with ONLY a JSON object, with no markdown code fences and no other text, in exactly this shape:
{"instruments": ["<form name>", ...]}
Use only form names from the list.
TXT;
	}

	public static function routingUserMessage(string $instrumentIndex, string $request, string $existingLogic, string $guidance, array $location): string
	{
		return self::userMessage("## Instruments (form name | label | fields)\n" . $instrumentIndex, $request, $existingLogic, $guidance, $location);
	}

	public static function retryMessage(string $originalMessage, string $previousLogic, array $errors): string
	{
		return $originalMessage
			. "\n\n## Your previous suggestion was invalid\nPrevious logic: $previousLogic\nProblems:\n- "
			. implode("\n- ", $errors)
			. "\nReturn a corrected JSON response.";
	}
}
