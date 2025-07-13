# AI Copilot Feature Design Prompt Template

---

## 1. Feature Overview
The Officer and Event Reporting Plugin enables high-level officers to create custom report forms for their direct reports and for special cases (e.g., events, injuries, equipment failures). These forms can be filled out by designated members or, for some forms, by any member. The plugin supports dynamic form creation, submission, review, and reporting workflows.

---

## 2. Business Goals
- Empower officers to collect structured, actionable data from their teams and events.
- Streamline reporting for incidents, events, and ad-hoc needs.
- Enable flexible, user-friendly form creation and submission.
- Ensure data security, auditability, and compliance with organizational policies.

---

## 3. User Stories
- As a high-level officer, I want to create custom report forms, so that I can collect the information I need from my team.
- As a member, I want to fill out assigned or open report forms, so that I can report incidents or provide required information.
- As an officer, I want to review submitted reports, so that I can take action or escalate as needed.
- As an admin, I want to manage permissions for who can create, view, and submit reports, so that sensitive data is protected.

---

## 4. Functional Requirements
- Officers can create, edit, and delete custom report forms with configurable fields (text, date, select, file upload, etc.).
- Forms can be assigned to specific members, offices, or made open to all members.
- Members can view and submit forms assigned to them or open forms.
- Officers can view, filter, and export submitted reports.
- Support for special report types: event, injury, equipment failure, and ad-hoc reports.
- Audit log of form creation, edits, and submissions.
- Notification system for assigned/completed reports (email or in-app).
- All actions are subject to authorization policies.

---

## 5. Technical Implementation Notes
- Implement as a CakePHP plugin: `plugins/OfficerEventReporting/`
- Use CakePHP conventions for Controllers, Models, Tables, Entities, Templates.
- Use Stimulus.js controllers for dynamic form UI in `plugins/OfficerEventReporting/assets/js/controllers/`.
- Store form definitions and submissions in plugin-specific tables (migrations required).
- Use Bootstrap for UI components.
- Register plugin in `config/plugins.php` and bootstrap in `src/Application.php`.
- Add authorization policies for officer/member/admin roles.
- Provide PHPUnit tests in `plugins/OfficerEventReporting/tests/` and JS tests for Stimulus controllers.

---

## 6. Edge Cases & Error Handling
- Validation for required fields, field types, and file uploads.
- Handle concurrent edits to forms (locking or last-write-wins).
- Prevent deletion of forms with existing submissions (or require confirmation).
- Graceful error messages for permission denied, missing forms, or failed submissions.
- Ensure only authorized users can view or submit sensitive reports.
- Handle large file uploads and enforce size/type restrictions.

---

## 7. Glossary
- Officer: User with permission to create/manage forms and review submissions.
- Member: User who can fill out and submit forms.
- Report Form: Customizable form for collecting structured data.
- Submission: A completed report form filled out by a member.

---

**Instructions:**
- Review these requirements for alignment with KMP best practices and codebase patterns.
- Use this document as the basis for implementation and further refinement.
