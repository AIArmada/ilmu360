@once
    @push('styles')
        <style>
            @media (max-width: 767px) {
                .mi-submit-person-form .fi-section {
                    border-radius: 1rem;
                    border-color: rgb(226 232 240 / 0.72);
                    background: rgb(255 255 255 / 0.96);
                    box-shadow: none;
                }

                .mi-submit-person-form .fi-section-header {
                    padding: 1rem 1rem 0.75rem;
                }

                .mi-submit-person-form .fi-section-content-ctn {
                    padding: 0 1rem 1rem;
                }

                .mi-submit-person-form .fi-section-content {
                    gap: 0.85rem;
                }

                .mi-submit-person-form .fi-input-wrp,
                .mi-submit-person-form .fi-select-input,
                .mi-submit-person-form .fi-select-control,
                .mi-submit-person-form .fi-fo-file-upload,
                .mi-submit-person-form .fi-fo-repeater-item {
                    border-radius: 0.95rem;
                }
            }
        </style>
    @endpush
@endonce
