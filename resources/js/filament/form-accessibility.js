(() => {
    let generatedFieldIndex = 0;
    const standardControlSelector = 'input:not([type="hidden"]), select, textarea, button.fi-select-input-btn, button[aria-haspopup], [contenteditable="true"]';
    const relaxedControlSelector = `${standardControlSelector}, button, [role="textbox"], [role="combobox"]`;

    const slugify = (value, fallback = 'field') => {
        const slug = String(value ?? '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');

        return slug || fallback;
    };

    const nextGeneratedValue = (seed, fallback = 'field') => {
        generatedFieldIndex += 1;

        return `${slugify(seed, fallback)}-${generatedFieldIndex}`;
    };

    const ensureControlIdentity = (root) => {
        root.querySelectorAll('input, select, textarea').forEach((control) => {
            if (control instanceof HTMLInputElement && control.type === 'hidden') {
                return;
            }

            if (!control.name) {
                control.name = nextGeneratedValue(
                    control.id || control.getAttribute('aria-label') || control.getAttribute('placeholder') || control.tagName,
                    'control',
                );
            }

            if (!control.id) {
                control.id = nextGeneratedValue(control.name || control.getAttribute('aria-label'), 'control');
            }
        });
    };

    const getFieldWrapper = (label) => label.closest('.fi-fo-field-wrp, [data-field-wrapper], .fi-fo-field, .fi-sc-component');

    const getPreferredCandidate = (label, fieldWrapper, includeButtons = false) => {
        const nestedCandidate = label.querySelector(includeButtons ? relaxedControlSelector : standardControlSelector);

        if (nestedCandidate instanceof HTMLElement) {
            return nestedCandidate;
        }

        if (label.closest('.filepond--drop-label')) {
            const fileUploadRoot = label.closest('.fi-fo-file-upload, .filepond--root') ?? fieldWrapper?.querySelector('.fi-fo-file-upload, .filepond--root');
            const fileInput = fileUploadRoot?.querySelector('.filepond--browser');

            if (fileInput instanceof HTMLElement) {
                return fileInput;
            }
        }

        return fieldWrapper?.querySelector(includeButtons ? relaxedControlSelector : standardControlSelector) ?? null;
    };

    const repairLabels = (root) => {
        root.querySelectorAll('label[for]').forEach((label) => {
            const targetId = label.getAttribute('for');
            const fieldWrapper = getFieldWrapper(label);
            const candidate = getPreferredCandidate(label, fieldWrapper);
            const target = targetId ? root.querySelector(`#${CSS.escape(targetId)}`) : null;

            if (!targetId) {
                return;
            }

            if (candidate instanceof HTMLInputElement && candidate.type === 'file' && target !== candidate) {
                if (!candidate.id) {
                    candidate.id = targetId || nextGeneratedValue(label.textContent, 'control');
                }

                label.setAttribute('for', candidate.id);
                label.id ||= nextGeneratedValue(label.textContent, 'label');
                candidate.setAttribute('aria-labelledby', label.id);

                return;
            }

            if (target) {
                return;
            }

            if (!candidate) {
                label.removeAttribute('for');

                return;
            }

            if (!candidate.id) {
                candidate.id = targetId || nextGeneratedValue(label.textContent, 'control');
            }

            label.setAttribute('for', candidate.id);
        });

        root.querySelectorAll('label:not([for])').forEach((label) => {
            const fieldWrapper = getFieldWrapper(label);
            const candidate = getPreferredCandidate(label, fieldWrapper, true);

            if (!candidate) {
                return;
            }

            if (!candidate.id) {
                candidate.id = nextGeneratedValue(label.textContent, 'control');
            }

            if (!label.id) {
                label.id = nextGeneratedValue(label.textContent, 'label');
            }

            label.setAttribute('for', candidate.id);
            candidate.setAttribute('aria-labelledby', label.id);
        });
    };

    const enhanceFormAccessibility = (root = document) => {
        ensureControlIdentity(root);
        repairLabels(root);
    };

    const scheduleEnhancements = () => {
        [0, 100, 300, 750, 1500, 2500].forEach((delay) => {
            window.setTimeout(() => {
                enhanceFormAccessibility(document);
            }, delay);
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        scheduleEnhancements();
    });

    document.addEventListener('livewire:navigated', () => {
        scheduleEnhancements();
    });

    const observer = new MutationObserver((mutations) => {
        const hasAddedElement = mutations.some((mutation) =>
            [...mutation.addedNodes].some((node) => node instanceof HTMLElement),
        );

        if (!hasAddedElement) {
            return;
        }

        enhanceFormAccessibility(document);
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
    });
})();
