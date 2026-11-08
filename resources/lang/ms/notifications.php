<?php

return [
    'menu' => 'Notifikasi',
    'actions' => [
        'open' => 'Buka notifikasi',
    ],
    'flash' => [
        'updated' => 'Tetapan notifikasi telah dikemas kini.',
        'device_removed' => 'Peranti telah dibuang.',
    ],
    'api' => [
        'read_success' => 'Notifikasi telah ditanda sebagai dibaca.',
        'read_all_success' => 'Semua notifikasi telah ditanda sebagai dibaca.',
        'push_registered' => 'Peranti push telah disambungkan.',
        'push_updated' => 'Maklumat peranti push telah dikemas kini.',
    ],
    'mail' => [
        'greeting' => 'Salam sejahtera :name,',
        'generic_recipient' => 'anda',
        'occurred_at' => 'Berlaku pada: :datetime',
        'footer' => 'Anda menerima mesej ini kerana tetapan notifikasi anda membenarkan kemas kini ini.',
    ],
    'auth' => [
        'actions' => [
            'open_dashboard' => 'Buka dashboard',
            'reset_password' => 'Tetapkan semula kata laluan',
            'verify_email' => 'Sahkan alamat e-mel',
        ],
        'verification' => [
            'subject' => 'Sahkan alamat e-mel anda',
            'intro' => 'Sila sahkan alamat e-mel anda untuk melengkapkan penyediaan akaun.',
            'outro' => 'Jika anda tidak mencipta akaun ini, tiada tindakan lanjut diperlukan.',
        ],
        'welcome' => [
            'subject' => 'Selamat datang ke :app',
            'intro' => 'Selamat datang ke :app.',
            'body' => 'Akaun anda sudah sedia digunakan. Anda kini boleh menyimpan majlis, mengikuti penceramah atau institusi, dan mengurus hantaran anda sendiri.',
            'verify_hint' => 'Sila sahkan alamat e-mel anda supaya notifikasi e-mel dan pemulihan akaun terus tersedia.',
            'footer' => 'Terima kasih kerana menyertai :app.',
        ],
        'reset_password' => [
            'subject' => 'Tetapkan semula kata laluan anda',
            'intro' => 'Kami menerima permintaan untuk menetapkan semula kata laluan akaun anda.',
            'expiry' => 'Pautan penetapan semula kata laluan ini akan luput dalam masa :count minit.',
            'outro' => 'Jika anda tidak meminta penetapan semula kata laluan, tiada tindakan lanjut diperlukan.',
        ],
    ],
    'membership' => [
        'invitation' => [
            'subject' => ':inviter menjemput anda menyertai :subject',
            'intro' => ':inviter menjemput anda menyertai :subject_label ini sebagai :role.',
            'subject_name' => 'Subjek: :name',
            'role' => 'Peranan: :role',
            'expires' => 'Luput pada: :datetime',
            'action' => 'Semak jemputan',
            'footer' => 'Log masuk menggunakan :email untuk menyemak dan menerima jemputan ini.',
        ],
    ],
    'moderation' => [
        'greeting' => 'Assalamualaikum,',
        'not_scheduled' => 'Belum dijadualkan',
        'actions' => [
            'review_event' => 'Semak majlis',
        ],
        'fields' => [
            'institution' => 'Institusi: :name',
            'event_datetime' => 'Waktu majlis: :datetime',
        ],
        'submitted' => [
            'subject' => 'Majlis baharu dihantar: :title',
            'intro' => 'Ada majlis baharu yang telah dihantar dan perlu disemak.',
            'public_submission' => 'Hantaran awam',
            'footer' => 'Mohon semak hantaran ini secepat mungkin.',
        ],
        'escalation' => [
            'subjects' => [
                '48_hours' => 'Majlis menunggu semakan lebih 48 jam: :title',
                '72_hours' => 'Eskalasi segera untuk majlis tertangguh: :title',
                'urgent' => 'Majlis sensitif masa sedang menunggu semakan: :title',
                'priority' => 'Majlis keutamaan tinggi sedang menunggu semakan: :title',
            ],
            'greetings' => [
                '48_hours' => 'Makluman SLA moderasi,',
                '72_hours' => 'Eskalasi segera,',
                'urgent' => 'Makluman majlis sensitif masa,',
                'priority' => 'Makluman majlis keutamaan tinggi,',
            ],
            'messages' => [
                '48_hours' => 'Majlis ini telah menunggu semakan moderator melebihi 48 jam.',
                '72_hours' => 'Majlis ini telah menunggu semakan moderator melebihi 72 jam dan perlukan tindakan segera.',
                'urgent' => 'Majlis ini masih menunggu semakan dan akan bermula dalam masa 24 jam.',
                'priority' => 'Majlis ini masih menunggu semakan dan akan bermula dalam masa 6 jam.',
            ],
            'urgent_footer' => 'Mohon semak majlis ini segera supaya statusnya dapat diputuskan sebelum ia bermula.',
            'priority_footer' => 'Majlis ini perlukan tindakan serta-merta kerana waktu mulanya sudah sangat hampir.',
        ],
    ],
    'reports' => [
        'resolved' => [
            'subject_resolved' => 'Laporan anda telah diselesaikan',
            'subject_dismissed' => 'Laporan anda telah ditutup',
            'intro' => 'Laporan anda berkaitan :entity telah disemak oleh pasukan moderasi kami.',
            'status' => 'Status: :status',
            'note' => 'Nota moderator: :note',
            'footer' => 'Terima kasih kerana membantu kami memastikan :app terus tepat dan berguna.',
            'action' => 'Lihat subjek laporan',
            'statuses' => [
                'resolved' => 'Diselesaikan',
                'dismissed' => 'Ditutup',
            ],
        ],
    ],
    'destinations' => [
        'unknown_device' => 'Peranti tidak dikenali',
        'not_available' => 'Belum tersedia',
        'email_ready' => 'E-mel sedia menerima notifikasi.',
        'push_devices' => '{0} Tiada peranti disambungkan|{1} 1 peranti disambungkan|[2,*] :count peranti disambungkan',
    ],
    'options' => [
        'cadence' => [
            'instant' => 'Serta-merta',
            'daily' => 'Ringkasan harian',
            'weekly' => 'Ringkasan mingguan',
            'off' => 'Tutup',
        ],
        'priority' => [
            'low' => 'Rendah',
            'medium' => 'Sederhana',
            'high' => 'Tinggi',
            'urgent' => 'Segera',
        ],
    ],
    'families' => [
        'followed_content' => [
            'label' => 'Kandungan Diikuti',
            'description' => 'Makluman apabila ada majlis baharu yang berkaitan dengan penceramah, institusi, siri, atau rujukan yang anda ikuti.',
        ],
        'saved_search_matches' => [
            'label' => 'Padanan Carian Tersimpan',
            'description' => 'Padanan baharu untuk carian tersimpan anda apabila majlis awam diterbitkan.',
        ],
        'event_updates' => [
            'label' => 'Kemas Kini Majlis',
            'description' => 'Perubahan penting untuk majlis yang anda simpan, rancang untuk hadir, atau sudah daftar.',
        ],
        'event_reminders' => [
            'label' => 'Peringatan Majlis',
            'description' => 'Peringatan berdasarkan masa untuk majlis yang anda akan hadiri atau sudah daftar.',
        ],
        'registration_checkin' => [
            'label' => 'Daftar & Daftar Masuk',
            'description' => 'Pengesahan dan susulan berkaitan pendaftaran serta daftar masuk majlis.',
        ],
        'submission_workflow' => [
            'label' => 'Aliran Hantar Majlis',
            'description' => 'Kemas kini moderasi untuk majlis dan entri direktori yang anda hantar atau bantu uruskan.',
        ],
        'sharing' => [
            'label' => 'Perkongsian',
            'description' => 'Ringkasan terima kasih yang tertunda untuk kesan perkongsian anda.',
        ],
    ],
    'triggers' => [
        'followed_person_event' => [
            'label' => 'Majlis baharu daripada penceramah diikuti',
            'description' => 'Maklumkan apabila ada majlis awam akan datang yang melibatkan penceramah yang saya ikuti.',
        ],
        'followed_institution_event' => [
            'label' => 'Majlis baharu daripada institusi diikuti',
            'description' => 'Maklumkan apabila ada majlis awam akan datang yang melibatkan institusi yang saya ikuti.',
        ],
        'followed_series_event' => [
            'label' => 'Majlis baharu daripada siri diikuti',
            'description' => 'Maklumkan apabila ada majlis awam akan datang yang melibatkan siri yang saya ikuti.',
        ],
        'followed_reference_event' => [
            'label' => 'Majlis baharu daripada rujukan diikuti',
            'description' => 'Maklumkan apabila ada majlis awam akan datang yang melibatkan rujukan yang saya ikuti.',
        ],
        'saved_search_match' => [
            'label' => 'Padanan carian tersimpan',
            'description' => 'Maklumkan apabila majlis baharu sepadan dengan carian tersimpan saya.',
        ],
        'event_approved' => [
            'label' => 'Majlis yang dijejak telah diluluskan',
            'description' => 'Maklumkan apabila majlis yang saya jejak sudah diluluskan untuk paparan awam.',
        ],
        'event_cancelled' => [
            'label' => 'Majlis dibatalkan',
            'description' => 'Hantar makluman segera apabila majlis yang saya jejak dibatalkan.',
        ],
        'event_schedule_changed' => [
            'label' => 'Jadual majlis berubah',
            'description' => 'Maklumkan apabila masa atau butiran utama majlis berubah secara penting.',
        ],
        'event_venue_changed' => [
            'label' => 'Lokasi majlis berubah',
            'description' => 'Maklumkan apabila lokasi atau ruang majlis bertukar.',
        ],
        'event_details_changed' => [
            'label' => 'Butiran majlis berubah',
            'description' => 'Maklumkan apabila penceramah, topik, rujukan, penganjur, atau butiran penting lain berubah.',
        ],
        'event_replacement_linked' => [
            'label' => 'Majlis ganti dipautkan',
            'description' => 'Maklumkan apabila majlis yang dijejak mempunyai majlis ganti.',
        ],
        'reminder_24_hours' => [
            'label' => 'Peringatan 24 jam',
            'description' => 'Hantar peringatan sehari sebelum majlis bermula.',
        ],
        'reminder_2_hours' => [
            'label' => 'Peringatan 2 jam',
            'description' => 'Hantar peringatan akhir tidak lama sebelum majlis bermula.',
        ],
        'checkin_open' => [
            'label' => 'Daftar masuk dibuka',
            'description' => 'Maklumkan apabila daftar masuk kendiri sudah dibuka.',
        ],
        'registration_confirmed' => [
            'label' => 'Pendaftaran berjaya',
            'description' => 'Sahkan pendaftaran majlis sebaik sahaja berjaya.',
        ],
        'registration_event_changed' => [
            'label' => 'Majlis berdaftar berubah',
            'description' => 'Maklumkan apabila majlis yang saya daftar berubah secara penting.',
        ],
        'checkin_confirmed' => [
            'label' => 'Daftar masuk berjaya',
            'description' => 'Sahkan bahawa daftar masuk majlis anda berjaya.',
        ],
        'submission_received' => [
            'label' => 'Hantaran diterima',
            'description' => 'Sahkan bahawa sesuatu hantaran sudah masuk ke aliran moderasi.',
        ],
        'submission_approved' => [
            'label' => 'Hantaran diluluskan',
            'description' => 'Maklumkan apabila salah satu hantaran saya diluluskan.',
        ],
        'submission_rejected' => [
            'label' => 'Hantaran ditolak',
            'description' => 'Maklumkan apabila salah satu hantaran saya ditolak.',
        ],
        'submission_needs_changes' => [
            'label' => 'Hantaran perlu pembetulan',
            'description' => 'Maklumkan apabila moderator meminta semakan atau pembetulan.',
        ],
        'submission_cancelled' => [
            'label' => 'Hantaran dibatalkan',
            'description' => 'Maklumkan apabila majlis yang dihantar dibatalkan.',
        ],
        'submission_remoderated' => [
            'label' => 'Hantaran disemak semula',
            'description' => 'Maklumkan apabila majlis yang dihantar masuk semula ke moderasi.',
        ],
        'share_gratitude_digest' => [
            'label' => 'Ringkasan terima kasih perkongsian',
            'description' => 'Ringkasan terima kasih yang tenang dan tertunda untuk kesan perkongsian yang saya buat.',
        ],
    ],
    'messages' => [
        'followed_content' => [
            'title' => ':title sepadan dengan sesuatu yang anda ikuti',
            'body' => 'Majlis ini berkaitan dengan: :matches. :timing.',
        ],
        'saved_search_match' => [
            'title' => ':title sepadan dengan carian tersimpan anda',
            'body' => 'Carian yang sepadan: :searches.',
        ],
        'event_approved' => [
            'title' => ':title kini telah diluluskan',
            'body' => 'Majlis ini kini dipaparkan secara awam. :timing.',
        ],
        'event_cancelled' => [
            'title' => ':title telah dibatalkan',
            'body' => 'Majlis ini tidak diteruskan. :timing.',
            'body_with_note' => 'Majlis ini tidak diteruskan. :timing. Nota: :note',
        ],
        'event_update' => [
            'title' => ':title telah dikemas kini',
        ],
        'event_change' => [
            'body' => ':summary',
        ],
        'event_schedule_changed' => [
            'body' => 'Jadual majlis telah berubah. :timing.',
        ],
        'event_venue_changed' => [
            'body' => 'Lokasi atau ruang majlis telah berubah. :timing.',
        ],
        'event_details_changed' => [
            'body' => ':summary',
        ],
        'event_replacement_linked' => [
            'body' => ':summary',
        ],
        'registration_confirmed' => [
            'title' => 'Pendaftaran berjaya untuk :title',
            'body' => 'Anda telah berjaya mendaftar. :timing.',
        ],
        'registration_event_changed' => [
            'title' => ':title berubah selepas anda mendaftar',
            'body' => 'Ada perubahan pada majlis yang anda daftar. :timing.',
        ],
        'checkin_confirmed' => [
            'title' => 'Daftar masuk berjaya untuk :title',
            'body' => 'Daftar masuk anda telah direkodkan.',
        ],
        'submission_received' => [
            'title' => 'Hantaran diterima: :title',
            'body' => 'Majlis ini sedang menunggu semakan moderator.',
        ],
        'submission_approved' => [
            'title' => 'Hantaran diluluskan: :title',
            'body' => 'Majlis anda telah diluluskan dan kini boleh dilihat umum. :timing.',
        ],
        'submission_rejected' => [
            'title' => 'Hantaran ditolak: :title',
            'body' => 'Hantaran ini telah ditolak.',
            'body_with_note' => 'Hantaran ini telah ditolak. Nota: :note',
        ],
        'directory_submission_approved' => [
            'title' => ':subject diluluskan: :title',
            'body' => 'Hantaran anda telah diluluskan dan kini boleh dilihat oleh umum.',
        ],
        'directory_submission_rejected' => [
            'title' => ':subject ditolak: :title',
            'body' => 'Hantaran ini telah ditolak.',
            'body_with_note' => 'Hantaran ini telah ditolak. Nota: :note',
        ],
        'submission_needs_changes' => [
            'title' => 'Pembetulan diminta untuk :title',
            'body' => 'Moderator meminta anda semak semula hantaran ini.',
            'body_with_note' => 'Moderator meminta anda semak semula hantaran ini. Nota: :note',
        ],
        'submission_cancelled' => [
            'title' => 'Hantaran dibatalkan: :title',
            'body' => 'Majlis yang dihantar ini telah dibatalkan.',
            'body_with_note' => 'Majlis yang dihantar ini telah dibatalkan. Nota: :note',
        ],
        'submission_remoderated' => [
            'title' => 'Hantaran disemak semula: :title',
            'body' => 'Hantaran ini telah masuk semula ke moderasi.',
            'body_with_note' => 'Hantaran ini telah masuk semula ke moderasi. Nota: :note',
        ],
        'share_gratitude_digest' => [
            'title' => 'Terima kasih kerana berkongsi',
            'body' => 'Perkongsian terbaru anda memberi kesan. :summary.',
        ],
        'reminder_24_hours' => [
            'title' => ':title bermula esok',
            'body' => 'Ini peringatan untuk majlis anda yang akan datang. :timing.',
        ],
        'reminder_2_hours' => [
            'title' => ':title akan bermula tidak lama lagi',
            'body' => 'Majlis ini akan bermula dalam lebih kurang dua jam. :timing.',
        ],
        'checkin_open' => [
            'title' => 'Daftar masuk telah dibuka untuk :title',
            'body' => 'Anda kini boleh daftar masuk untuk majlis ini.',
        ],
        'digest' => [
            'title' => ':count kemas kini sedia untuk disemak',
            'body' => 'Buka notifikasi anda untuk melihat kemas kini terkini.',
        ],
    ],
    'pages' => [
        'settings' => [
            'tab' => 'Notifikasi',
            'heading' => 'Notifikasi',
            'description' => 'Pilih apa yang anda mahu dengar. Kami uruskan cara terbaik untuk menyampaikannya.',
            'mandatory_heading' => 'Makluman wajib',
            'mandatory_body' => 'Pembatalan, perubahan masa atau lokasi, dan resit pendaftaran untuk majlis yang anda sertai sentiasa dihantar.',
            'channels_heading' => 'Saluran',
            'channels_description' => 'E-mel dan push. Dalam aplikasi sentiasa aktif untuk makluman wajib.',
            'channel_in_app' => 'Dalam aplikasi',
            'channel_in_app_help' => 'Peti masuk anda. Sentiasa aktif untuk makluman wajib.',
            'channel_in_app_status' => 'Sentiasa aktif',
            'channel_email' => 'E-mel',
            'channel_push' => 'Push',
            'manage_devices' => 'Urus peranti',
            'channel_unavailable' => 'Sahkan di profil',
            'channel_push_unavailable' => 'Akan datang — aplikasi mudah alih masih dalam pembangunan.',
            'groups_heading' => 'Apa yang anda terima',
            'groups_description' => 'Hidupkan yang berguna. Matikan yang tidak.',
            'discovery_heading' => 'Majlis baharu untuk anda',
            'discovery_description' => 'Majlis baharu daripada penceramah, institusi, siri dan carian yang anda ikuti.',
            'cadence_label' => 'Kekerapan',
            'my_events_heading' => 'Majlis yang saya sertai',
            'my_events_description' => 'Peringatan dan daftar masuk untuk majlis yang anda daftar. Perubahan penting dihantar sebagai makluman wajib.',
            'submission_heading' => 'Sumbangan majlis',
            'submission_description' => 'Status semakan untuk majlis yang anda hantar. Hanya dipaparkan jika anda pernah menghantar.',
            'organizer_badge' => 'Penganjur',
            'quiet_hours_heading' => 'Waktu senyap',
            'quiet_hours_description' => 'Kami tahan notifikasi biasa. Makluman wajib tetap dihantar.',
            'quiet_hours_label' => 'Jangan ganggu',
            'quiet_hours_help' => 'Mengikut zon waktu profil anda.',
            'quiet_hours_start' => 'Mula',
            'quiet_hours_end' => 'Tamat',
            'devices_heading' => 'Peranti',
            'devices_description' => 'Peranti yang menerima push. Buang peranti yang tidak digunakan.',
            'device_last_seen' => 'Aktif :time',
            'device_remove' => 'Buang',
            'device_remove_confirm' => 'Buang peranti ini daripada notifikasi push?',
            'devices_empty' => 'Tiada peranti disambungkan.',
            'save_button' => 'Simpan perubahan',
            'footer_note' => 'Push dihantar ke aplikasi mudah alih melalui sistem operasi.',
        ],
        'inbox' => [
            'nav_label' => 'Notifikasi',
            'cta' => 'Buka peti notifikasi',
        ],
    ],
    'app_feedback' => [
        'received' => [
            'subject' => 'Maklum balas diterima: :title',
            'intro' => 'Kami telah menerima maklum balas anda. Pasukan kami akan menyemaknya.',
            'reference' => 'Rujukan: :id',
            'status' => 'Status semasa: :status',
            'action' => 'Lihat maklum balas',
            'footer' => 'Terima kasih kerana membantu kami menambah baik :app.',
        ],
        'submitted' => [
            'subject' => 'Maklum balas aplikasi baharu: :title',
            'intro' => 'Ada maklum balas aplikasi baharu yang perlu disemak.',
            'category' => 'Kategori: :category',
            'action' => 'Buka dalam panel admin',
            'footer' => 'Semak maklum balas ini apabila sempat.',
        ],
        'closed' => [
            'subject_resolved' => 'Maklum balas anda telah diselesaikan: :title',
            'subject_declined' => 'Maklum balas anda telah ditutup: :title',
            'intro' => 'Maklum balas anda telah disemak oleh pasukan kami.',
            'status' => 'Status: :status',
            'note' => 'Catatan: :note',
            'action' => 'Lihat maklum balas',
            'footer' => 'Terima kasih kerana membantu kami menambah baik :app.',
        ],
        'triaged' => [
            'subject' => 'Maklum balas anda sedang disemak: :title',
            'intro' => 'Pasukan kami telah mula menyemak maklum balas anda. Kami akan memaklumkan anda apabila selesai.',
            'status' => 'Status semasa: :status',
            'action' => 'Lihat maklum balas',
            'footer' => 'Terima kasih kerana membantu kami menambah baik :app.',
        ],
        'replied' => [
            'subject' => 'Balasan baharu untuk maklum balas anda: :title',
            'intro' => 'Pasukan kami telah membalas maklum balas anda.',
            'reply_label' => 'Balasan:',
            'status' => 'Status semasa: :status',
            'action' => 'Lihat balasan',
            'footer' => 'Terima kasih kerana membantu kami menambah baik :app.',
        ],
    ],
    'inbox' => [
        'page_title' => 'Notifikasi',
        'eyebrow' => 'Peti Notifikasi',
        'heading' => 'Semua makluman untuk anda',
        'description' => 'Semak makluman yang belum dibaca, buka halaman berkaitan, dan lihat apa yang sudah selesai diteliti.',
        'unread_count' => '{0} Tiada notifikasi baharu|{1} :count notifikasi belum dibaca|[2,*] :count notifikasi belum dibaca',
        'manage_settings' => 'Urus tetapan notifikasi',
        'mark_all_read' => 'Tandakan semua sebagai dibaca',
        'family_filter' => 'Kumpulan',
        'all_families' => 'Semua kumpulan',
        'status_filter' => 'Status',
        'status' => [
            'unread' => 'Belum dibaca',
            'read' => 'Dibaca',
            'all' => 'Semua',
        ],
        'channels_attempted' => 'Saluran',
        'mark_read' => 'Tandakan sebagai dibaca',
        'open_link' => 'Buka',
        'pagination' => [
            'label' => 'Halaman notifikasi',
            'summary' => 'Memaparkan :from–:to daripada :total notifikasi',
            'previous' => 'Sebelumnya',
            'next' => 'Seterusnya',
        ],
        'empty' => [
            'heading' => 'Belum ada apa-apa untuk disemak',
            'description' => 'Makluman baharu akan muncul di sini apabila sesuatu yang anda ikuti atau jejak memerlukan perhatian anda.',
            'cta' => 'Lihat majlis',
            'view_all' => 'Lihat semua notifikasi',
        ],
    ],
    'bell' => [
        'label' => 'Notifikasi',
        'unread_aria' => 'Notifikasi, :count belum dibaca',
        'heading' => 'Notifikasi',
        'menu_label' => 'Pilihan notifikasi',
        'mark_all_read' => 'Tandakan semua sebagai dibaca',
        'settings' => 'Tetapan notifikasi',
        'open' => 'Buka notifikasi',
        'tabs' => [
            'label' => 'Penapis notifikasi',
            'all' => 'Semua',
            'unread' => 'Belum dibaca',
        ],
        'today' => 'Hari ini',
        'earlier' => 'Terdahulu',
        'see_all' => 'Lihat semua notifikasi',
        'empty' => [
            'heading' => 'Belum ada notifikasi',
            'description' => 'Kemas kini tentang majlis, sumbangan, dan perkara yang anda ikuti akan muncul di sini.',
        ],
        'empty_unread' => [
            'heading' => 'Semua sudah dibaca',
            'description' => 'Tiada notifikasi yang belum dibaca.',
        ],
        'loading' => 'Memuatkan notifikasi...',
        'load_failed' => 'Tidak dapat memuatkan notifikasi.',
        'retry' => 'Cuba lagi',
        'unread_dot' => 'Belum dibaca',
    ],
];
