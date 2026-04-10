import '../../../assets/js/controllers/email-template-form-controller.js';

const EmailTemplateFormController = window.Controllers['email-template-form'];

describe('EmailTemplateFormController', () => {
    let controller;

    beforeEach(() => {
        document.body.innerHTML = `
            <div data-controller="email-template-form">
                <input type="text" data-email-template-form-target="nameField" value="">
                <input type="text" data-email-template-form-target="slugField" value="">
                <input type="text" data-email-template-form-target="availableVars" value="">
                <input type="text" data-email-template-form-target="subjectTemplate" value="">
                <textarea data-email-template-form-target="htmlTemplate"></textarea>
                <textarea data-email-template-form-target="textTemplate"></textarea>
                <div data-email-template-form-target="parsedVarsPanel" style="display:none;"></div>
                <div data-email-template-form-target="parsedVarsList"></div>
            </div>
        `;

        controller = new EmailTemplateFormController();
        controller.element = document.querySelector('[data-controller="email-template-form"]');
        controller.availableVarsTarget = document.querySelector('[data-email-template-form-target="availableVars"]');
        controller.subjectTemplateTarget = document.querySelector('[data-email-template-form-target="subjectTemplate"]');
        controller.nameFieldTarget = document.querySelector('[data-email-template-form-target="nameField"]');
        controller.slugFieldTarget = document.querySelector('[data-email-template-form-target="slugField"]');
        controller.htmlTemplateTarget = document.querySelector('[data-email-template-form-target="htmlTemplate"]');
        controller.textTemplateTarget = document.querySelector('[data-email-template-form-target="textTemplate"]');
        controller.parsedVarsPanelTarget = document.querySelector('[data-email-template-form-target="parsedVarsPanel"]');
        controller.parsedVarsListTarget = document.querySelector('[data-email-template-form-target="parsedVarsList"]');
        controller.hasAvailableVarsTarget = true;
        controller.hasSubjectTemplateTarget = true;
        controller.hasNameFieldTarget = true;
        controller.hasSlugFieldTarget = true;
        controller.hasHtmlTemplateTarget = true;
        controller.hasTextTemplateTarget = true;
        controller.hasParsedVarsPanelTarget = true;
        controller.hasParsedVarsListTarget = true;
    });

    afterEach(() => {
        document.body.innerHTML = '';
        jest.restoreAllMocks();
    });

    test('registers on window.Controllers', () => {
        expect(window.Controllers['email-template-form']).toBe(EmailTemplateFormController);
    });

    test('has correct static targets', () => {
        expect(EmailTemplateFormController.targets).toEqual(
            expect.arrayContaining([
                'availableVars',
                'subjectTemplate',
                'nameField',
                'slugField',
                'htmlTemplate',
                'textTemplate',
                'parsedVarsPanel',
                'parsedVarsList',
            ]),
        );
    });

    test('nameChanged generates slug from name when slug is empty', () => {
        controller.nameFieldTarget.value = 'Warrant Issued';
        controller.slugFieldTarget.value = '';
        controller.nameChanged();

        expect(controller.slugFieldTarget.value).toBe('warrant-issued');
    });

    test('nameChanged does not overwrite a manually-entered slug', () => {
        controller.nameFieldTarget.value = 'Warrant Issued';
        controller.slugFieldTarget.value = 'my-custom-slug';
        controller.nameChanged();

        expect(controller.slugFieldTarget.value).toBe('my-custom-slug');
    });

    test('_slugify strips special characters', () => {
        expect(controller._slugify("Officer's Appointment & Role!")).toBe('officers-appointment-role');
    });

    test('_extractPlaceholders extracts unique variable names', () => {
        const vars = controller._extractPlaceholders('Hello {{name}}, your award is {{awardName}} and {{name}} again');
        expect(vars).toEqual(['awardName', 'name']);
    });

    test('_extractPlaceholders ignores control keywords', () => {
        const vars = controller._extractPlaceholders('{{#if condition}}Hello {{name}}{{/if}}{{else}}');
        expect(vars).toEqual(['name']);
    });

    test('templateChanged shows parsed vars panel when placeholders found', () => {
        controller.htmlTemplateTarget.value = 'Hello {{recipientName}}, your warrant {{warrantTitle}} is ready.';
        controller.textTemplateTarget.value = '';
        controller.templateChanged();

        expect(controller.parsedVarsPanelTarget.style.display).not.toBe('none');
        const badges = controller.parsedVarsListTarget.querySelectorAll('code');
        expect(badges).toHaveLength(2);
        expect(badges[0].textContent).toContain('recipientName');
        expect(badges[1].textContent).toContain('warrantTitle');
    });

    test('templateChanged hides parsed vars panel when no placeholders found', () => {
        controller.parsedVarsPanelTarget.style.display = '';
        controller.htmlTemplateTarget.value = 'No variables here';
        controller.textTemplateTarget.value = '';
        controller.templateChanged();

        expect(controller.parsedVarsPanelTarget.style.display).toBe('none');
    });

    test('templateChanged combines html and text template placeholders', () => {
        controller.htmlTemplateTarget.value = '{{htmlVar}}';
        controller.textTemplateTarget.value = '{{textVar}}';
        controller.templateChanged();

        const names = Array.from(controller.parsedVarsListTarget.querySelectorAll('code')).map((badge) => badge.textContent);
        expect(names).toContain('{{htmlVar}}');
        expect(names).toContain('{{textVar}}');
    });
});
