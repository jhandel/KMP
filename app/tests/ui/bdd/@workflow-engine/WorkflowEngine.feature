Feature: Workflow Engine Administration
    As an admin user of the KMP system
    I want to manage workflow definitions through the visual editor
    So that I can configure business processes without code changes

    Background:
        Given I am logged in as admin

    Scenario: Access workflow definitions index page
        When I navigate to the workflow engine page
        Then I should see the workflow definitions list
        And I should see the "Create New Workflow" button
        And I should see seeded workflow definitions

    Scenario: View seeded workflow definitions details
        When I navigate to the workflow engine page
        Then I should see a workflow named "Award Recommendations"
        And I should see a workflow named "Warrant Lifecycle"
        And I should see a workflow named "Activity Authorization"

    Scenario: Access the workflow editor for a definition
        When I navigate to the workflow engine page
        And I click the edit button for the first workflow
        Then I should see the workflow editor
        And I should see the editor toolbar
        And I should see the editor canvas

    Scenario: Access the create new workflow page
        When I navigate to the workflow engine page
        And I click the "Create New Workflow" button
        Then I should see the create workflow form
        And I should see the name field
        And I should see the slug field
        And I should see the entity type field

    Scenario: Create a new workflow definition
        When I navigate to the create workflow page
        And I fill in the workflow form with a unique slug
        And I submit the workflow form
        Then I should be redirected to the workflow editor

    Scenario: Access workflow analytics page
        When I navigate to the workflow analytics page
        Then I should see the analytics dashboard
        And I should see the active instances count
        And I should see the completed instances count

    Scenario: Non-admin cannot access workflow engine
        Given I logout and login as a basic user
        When I try to navigate to the workflow engine page
        Then I should not see the workflow definitions list

    Scenario: Workflow editor displays states as nodes
        When I navigate to the workflow engine page
        And I click the edit button for the first workflow
        Then I should see state nodes on the canvas

    Scenario: Workflow editor has Add State button
        When I navigate to the workflow engine page
        And I click the edit button for the first workflow
        Then I should see the "Add State" button in the toolbar

    Scenario: Approval gate config appears for approval states
        When I navigate to the workflow engine page
        And I open the editor for the "Warrant Roster Approval" workflow
        And I click on an approval state node
        Then I should see the approval gate configuration section

    Scenario: Delete a workflow definition
        When I navigate to the create workflow page
        And I fill in the workflow form for deletion test
        And I submit the workflow form
        Then I should be redirected to the workflow editor
        When I navigate to the workflow engine page
        And I delete the workflow named "Deletable Workflow"
        Then I should see the flash message containing "deleted"
