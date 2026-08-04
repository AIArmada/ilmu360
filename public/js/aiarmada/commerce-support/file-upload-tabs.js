(() => {
    const initializeFileUploadComponents = (attempt = 0) => {
        if (!window.Alpine) {
            return;
        }

        let hasPendingComponents = false;

        document.querySelectorAll('[x-data*="fileUploadFormComponent"]').forEach((root) => {
            if (root._x_async === 'init' || root._x_async === 'await') {
                hasPendingComponents = true;

                return;
            }

            const component = root._x_dataStack?.[0];

            if (component && !component.pond && typeof component.init === 'function') {
                component.init();
            }
        });

        if (hasPendingComponents && attempt < 40) {
            window.setTimeout(() => initializeFileUploadComponents(attempt + 1), 50);
        }
    };

    const scheduleFileUploadInitialization = () => {
        [0, 100, 300, 750, 1500].forEach((delay) => {
            window.setTimeout(initializeFileUploadComponents, delay);
        });
    };

    document.addEventListener('alpine:initialized', () => initializeFileUploadComponents(), { once: true });
    document.addEventListener('livewire:navigated', () => initializeFileUploadComponents());

    const start = () => scheduleFileUploadInitialization();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }

    const observer = new MutationObserver((mutations) => {
        const hasAddedElement = mutations.some((mutation) =>
            [...mutation.addedNodes].some((node) => node instanceof HTMLElement),
        );
        const hasActivatedTab = mutations.some((mutation) =>
            mutation.type === 'attributes'
            && mutation.attributeName === 'class'
            && mutation.target instanceof HTMLElement
            && mutation.target.matches('.fi-sc-tabs-tab'),
        );

        if (hasAddedElement || hasActivatedTab) {
            scheduleFileUploadInitialization();
        }
    });

    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
        childList: true,
        subtree: true,
    });
})();
