<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class InitOfficerEventReporting extends BaseMigration
{
    public bool $autoId = false;

    /**
     * Up method to create tables for Officer Event Reporting
     * 
     * @return void
     */
    public function up(): void
    {
        // Create forms table
        $this->table("officer_event_reporting_forms")
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("title", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("description", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("form_type", "string", [
                "default" => "ad-hoc",
                "limit" => 50,
                "null" => false,
                "comment" => "ad-hoc, event, injury, equipment-failure",
            ])
            ->addColumn("status", "string", [
                "default" => "active",
                "limit" => 20,
                "null" => false,
                "comment" => "active, inactive, archived",
            ])
            ->addColumn("assignment_type", "string", [
                "default" => "open",
                "limit" => 20,
                "null" => false,
                "comment" => "open, assigned, office-specific",
            ])
            ->addColumn("assigned_members", "text", [
                "default" => null,
                "null" => true,
                "comment" => "JSON array of member IDs for assigned forms",
            ])
            ->addColumn("assigned_offices", "text", [
                "default" => null,
                "null" => true,
                "comment" => "JSON array of office IDs for office-specific forms",
            ])
            ->addColumn("created_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("modified_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => false,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addPrimaryKey(["id"])
            ->addIndex(["created_by"])
            ->addIndex(["status"])
            ->addIndex(["form_type"])
            ->create();

        // Create form_fields table
        $this->table("officer_event_reporting_form_fields")
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("form_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("field_name", "string", [
                "default" => null,
                "limit" => 100,
                "null" => false,
            ])
            ->addColumn("field_label", "string", [
                "default" => null,
                "limit" => 255,
                "null" => false,
            ])
            ->addColumn("field_type", "string", [
                "default" => "text",
                "limit" => 50,
                "null" => false,
                "comment" => "text, textarea, select, radio, checkbox, date, datetime, file, email, number",
            ])
            ->addColumn("field_options", "text", [
                "default" => null,
                "null" => true,
                "comment" => "JSON for select/radio options, file type restrictions, etc.",
            ])
            ->addColumn("is_required", "boolean", [
                "default" => false,
                "null" => false,
            ])
            ->addColumn("sort_order", "integer", [
                "default" => 0,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => false,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addPrimaryKey(["id"])
            ->addIndex(["form_id"])
            ->addForeignKey("form_id", "officer_event_reporting_forms", "id", [
                "delete" => "CASCADE",
                "update" => "CASCADE",
            ])
            ->create();

        // Create submissions table
        $this->table("officer_event_reporting_submissions")
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("form_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("submitted_by", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("status", "string", [
                "default" => "submitted",
                "limit" => 20,
                "null" => false,
                "comment" => "submitted, reviewed, approved, rejected",
            ])
            ->addColumn("reviewer_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => true,
            ])
            ->addColumn("review_notes", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("reviewed_at", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => false,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addPrimaryKey(["id"])
            ->addIndex(["form_id"])
            ->addIndex(["submitted_by"])
            ->addIndex(["status"])
            ->addForeignKey("form_id", "officer_event_reporting_forms", "id", [
                "delete" => "CASCADE",
                "update" => "CASCADE",
            ])
            ->create();

        // Create submission_values table
        $this->table("officer_event_reporting_submission_values")
            ->addColumn("id", "integer", [
                "autoIncrement" => true,
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("submission_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("form_field_id", "integer", [
                "default" => null,
                "limit" => 11,
                "null" => false,
            ])
            ->addColumn("field_value", "text", [
                "default" => null,
                "null" => true,
            ])
            ->addColumn("created", "datetime", [
                "default" => null,
                "null" => false,
            ])
            ->addColumn("modified", "datetime", [
                "default" => null,
                "null" => true,
            ])
            ->addPrimaryKey(["id"])
            ->addIndex(["submission_id"])
            ->addIndex(["form_field_id"])
            ->addForeignKey("submission_id", "officer_event_reporting_submissions", "id", [
                "delete" => "CASCADE",
                "update" => "CASCADE",
            ])
            ->addForeignKey("form_field_id", "officer_event_reporting_form_fields", "id", [
                "delete" => "CASCADE",
                "update" => "CASCADE",
            ])
            ->create();
    }

    /**
     * Down method to drop tables
     * 
     * @return void
     */
    public function down(): void
    {
        $this->table("officer_event_reporting_submission_values")->drop()->save();
        $this->table("officer_event_reporting_submissions")->drop()->save();
        $this->table("officer_event_reporting_form_fields")->drop()->save();
        $this->table("officer_event_reporting_forms")->drop()->save();
    }
}