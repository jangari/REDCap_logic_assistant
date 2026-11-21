---
version: "1.0.0"
level: copilot
---

## Notes

Anthropic's Claude Opus 5.5 model, via Claude Code, wrote the initial implementation of this
module from the author's direction. That covers the module code, the prompts sent to the GenAI
service, the validation of suggested logic, the logic editor integration, the AI disclaimer and
this documentation.

Before writing code, Claude researched REDCap core: how the GenAI settings resolve a project's
AI configuration, the logic editor's JavaScript, every logic box that opens the editor, and the
logic validation and Smart Variable names available in this REDCap version.

Aidan Wilson made all product decisions and redirected the work throughout:

- the concept, and exploring options before building;
- relying solely on REDCap's configured GenAI service (system level or project override), and
  removing a separate AI endpoint setting Claude had initially added;
- hiding the assistant when GenAI is not enabled for a project;
- offering the assistant on every logic editor, with the kind of logic chosen in a dropdown, and
  not adding integrations that depend on other modules being installed;
- showing an "AI-generated" notice on every suggestion;
- requiring a per-user disclaimer that is accepted once a year;
- deferring scheduling-related context to a later version;
- the wording and scope of this documentation.

The module was tested by the author in a local REDCap development instance. Claude also
diagnosed why the assistant was initially not appearing there: the project had no GenAI
configuration in effect.
