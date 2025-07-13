# Officer and Event Reporting Plugin

This plugin provides a comprehensive form management and reporting system for KMP, allowing officers to create custom forms for data collection and members to submit reports.

## Features

- **Dynamic Form Creation**: Officers can create custom forms with various field types
- **Flexible Assignment**: Forms can be open to all, assigned to specific members, or office-specific
- **Multiple Report Types**: Support for ad-hoc, event, injury, and equipment failure reports
- **Review Workflow**: Officers can review, approve, or reject submissions
- **Responsive UI**: Bootstrap-based interface with Stimulus.js interactivity
- **Authorization**: Role-based access control with policies

## Installation

The plugin is automatically loaded when KMP is installed. It includes:

- Database migrations for forms, form fields, submissions, and submission values
- Navigation integration
- Authorization policies
- Responsive templates

## Usage

### For Officers

1. **Create Forms**: Navigate to Report Forms → Create New Form
2. **Manage Forms**: View, edit, or delete existing forms
3. **Review Submissions**: Review submitted reports and update their status

### For Members

1. **View Available Forms**: Browse forms available for submission
2. **Submit Reports**: Fill out and submit assigned or open forms
3. **Track Submissions**: View the status of submitted reports

## Database Schema

The plugin creates four main tables:

- `officer_event_reporting_forms`: Form definitions
- `officer_event_reporting_form_fields`: Dynamic form fields
- `officer_event_reporting_submissions`: Form submissions
- `officer_event_reporting_submission_values`: Individual field values

## Field Types Supported

- Text input
- Textarea
- Select dropdown
- Radio buttons
- Checkboxes
- Date picker
- DateTime picker
- File upload
- Email input
- Number input

## Authorization

The plugin implements role-based access control:

- **Officers**: Can create, edit, delete forms and review submissions
- **Members**: Can view available forms and submit reports
- **Submitters**: Can edit their own submissions if not yet reviewed

## Configuration

Plugin settings are managed through the application settings system:

- Maximum file upload size
- Allowed file types
- Plugin activation status

## Technical Details

- Built for CakePHP 5.x
- Uses Stimulus.js for dynamic UI interactions
- Follows KMP plugin architecture patterns
- Includes PHPUnit tests
- Implements CakePHP authorization patterns