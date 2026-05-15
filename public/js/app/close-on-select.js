/**
 * Custom Alpine directive to close Filament Select dropdown after selection
 * when used with multiple() selects.
 *
 * Usage: Add x-close-on-select to select components that should close after each selection.
 */
document.addEventListener('alpine:init', () => {
    Alpine.directive('close-on-select', (el) => {
        // Wait for the Select component to initialize
        const observer = new MutationObserver((mutations, obs) => {
            const selectContainer = el.querySelector('.fi-select-input-ctn');
            if (selectContainer) {
                obs.disconnect();
                setupCloseOnSelect(selectContainer);
            }
        });

        observer.observe(el, { childList: true, subtree: true });

        // Also try immediately in case it's already rendered
        const selectContainer = el.querySelector('.fi-select-input-ctn');
        if (selectContainer) {
            observer.disconnect();
            setupCloseOnSelect(selectContainer);
        }
    });
});

/**
 * Set up the close-on-select behavior for a Filament Select container
 */
function setupCloseOnSelect(selectContainer) {
    // Listen for clicks on options within the dropdown
    selectContainer.addEventListener('click', (event) => {
        const option = event.target.closest('.fi-select-input-option');
        if (!option) return;

        // Small delay to allow the selection to be processed first
        setTimeout(() => {
            const dropdown = selectContainer.querySelector('.fi-dropdown-panel');
            if (dropdown && dropdown.style.display !== 'none') {
                // Simulate closing by triggering the button click
                const selectButton = selectContainer.querySelector('.fi-select-input-btn');
                if (selectButton) {
                    // dispatch a custom event to close the dropdown
                    selectButton.click();
                }
            }
        }, 50);
    }, true);
}
