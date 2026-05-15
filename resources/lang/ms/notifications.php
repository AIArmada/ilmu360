<?php

return [
    'menu' => 'Notifikasi',
    'actions' => [
        'open' => 'Buka notifikasi',
    ],
    'flash' => [
        'updated' => 'Tetapan notifikasi telah dikemas kini.',
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
        'email_pending' => 'Tambah dan sahkan alamat e-mel untuk guna saluran e-mel.',
        'whatsapp_ready' => 'WhatsApp sedia digunakan pada nombor telefon yang telah disahkan.',
        'whatsapp_pending' => 'Sahkan nombor telefon anda dahulu sebelum WhatsApp boleh digunakan.',
        'push_devices' => '{0} Tiada peranti disambungkan|{1} 1 peranti disambungkan|[2,*] :count peranti disambungkan',
        'push_ready' => 'Peranti yang log masuk boleh menerima notifikasi push.',
        'push_pending' => 'Notifikasi push akan muncul selepas anda log masuk melalui aplikasi mudah alih.',
    ],
    'options' => [
        'cadence' => [
            'instant' => 'Serta-merta',
            'daily' => 'Ringkasan harian',
            'weekly' => 'Ringkasan mingguan',
            'off' => 'Tutup',
        ],
        'fallback' => [
            'next_available' => 'Cuba saluran lain yang tersedia',
            'in_app_only' => 'Simpan dalam aplikasi sahaja',
            'skip' => 'Jangan hantar melalui saluran luar',
        ],
        'weekdays' => [
            'monday' => 'Isnin',
            'tuesday' => 'Selasa',
            'wednesday' => 'Rabu',
            'thursday' => 'Khamis',
            'friday' => 'Jumaat',
            'saturday' => 'Sabtu',
            'sunday' => 'Ahad',
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
    ],
    'triggers' => [
        'followed_speaker_event' => [
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
    'ui' => [
        'tabs' => [
            'profile' => 'Profil',
            'notifications' => 'Notifikasi',
        ],
        'save' => 'Simpan Tetapan Notifikasi',
        'delivery' => [
            'eyebrow' => 'Tetapan Penghantaran',
            'heading' => 'Cara anda mahu menerima kemas kini',
            'description' => 'Tentukan masa penghantaran, waktu senyap, aturan saluran, dan tindakan apabila saluran utama tidak tersedia.',
            'locale' => 'Bahasa notifikasi',
            'locale_help' => 'Bahasa ini digunakan apabila notifikasi dibina untuk penghantaran luar aplikasi.',
            'timezone' => 'Zon waktu penghantaran',
            'timezone_help' => 'Jadual ringkasan dan waktu senyap mengikut zon waktu dalam profil anda.',
            'manage_timezone' => 'Urus zon waktu di profil',
            'quiet_hours_start' => 'Waktu senyap bermula',
            'quiet_hours_end' => 'Waktu senyap tamat',
            'quiet_hours_help' => 'Push dan WhatsApp akan ditangguh sehingga waktu senyap tamat kecuali notifikasi itu mendesak.',
            'quiet_hours_end_help' => 'Biarkan kedua-dua ruang ini kosong jika anda tidak mahu had waktu senyap.',
            'digest_delivery_time' => 'Masa penghantaran ringkasan',
            'digest_delivery_time_help' => 'Ringkasan harian dan mingguan akan dihantar sekitar masa tempatan ini.',
            'digest_weekly_day' => 'Hari ringkasan mingguan',
            'digest_weekly_day_help' => 'Pilih hari untuk menerima ringkasan mingguan.',
            'fallback_strategy' => 'Tindakan apabila saluran gagal',
            'fallback_strategy_help' => 'Tentukan apa yang perlu dibuat jika saluran utama tidak tersedia.',
            'preferred_channels' => 'Susunan saluran pilihan',
            'preferred_channels_help' => 'Susun saluran mengikut keutamaan anda apabila lebih daripada satu saluran dibenarkan.',
            'channel_slot' => 'Keutamaan :number',
            'no_preference' => 'Tiada pilihan',
            'urgent_override' => 'Benarkan notifikasi mendesak melepasi waktu senyap',
        ],
        'destinations' => [
            'eyebrow' => 'Destinasi Tersambung',
            'heading' => 'Saluran yang boleh menerima notifikasi',
            'description' => 'E-mel menggunakan alamat akaun anda, WhatsApp menggunakan nombor telefon yang telah disahkan, dan push datang daripada aplikasi mudah alih yang disambungkan.',
            'verified' => 'Disahkan',
            'needs_verification' => 'Perlu pengesahan',
            'connected' => 'Tersambung',
            'none_connected' => 'Tiada peranti',
            'email_help' => 'E-mel ini sedia menerima notifikasi.',
            'add_email_help' => 'Tambah alamat e-mel di profil anda jika mahu menerima notifikasi e-mel.',
            'whatsapp_help' => 'WhatsApp tersedia kerana nombor telefon anda telah disahkan.',
            'whatsapp_unverified_help' => 'Sahkan nombor telefon anda di profil sebelum menghidupkan notifikasi WhatsApp.',
            'push_help' => 'Sambungkan aplikasi mudah alih di iPhone atau Android untuk menerima notifikasi push.',
            'last_seen' => 'Kali terakhir aktif: :date',
            'push_devices_count' => '{0} Tiada peranti tersambung|{1} :count peranti tersambung|[2,*] :count peranti tersambung',
        ],
        'families' => [
            'eyebrow' => 'Kumpulan Notifikasi',
            'heading' => 'Pilih maklumat yang penting untuk anda',
            'description' => 'Setiap kumpulan menentukan irama asas dan saluran lalai untuk notifikasi yang berkaitan.',
            'enabled' => 'Aktif',
            'cadence' => 'Irama lalai',
            'channels' => 'Saluran lalai',
            'trigger_count' => '{1} :count jenis makluman|[2,*] :count jenis makluman',
        ],
        'triggers' => [
            'summary' => 'Laraskan makluman tertentu',
            'enabled' => 'Aktif',
            'use_family_defaults' => 'Ikut tetapan kumpulan',
            'inherits_family_help' => 'Makluman ini akan ikut irama dan saluran kumpulan di atas sehingga anda tetapkan secara khusus.',
            'cadence' => 'Irama khusus',
            'channels' => 'Saluran khusus',
            'urgent_override' => 'Benarkan makluman ini melepasi waktu senyap apabila mendesak',
        ],
    ],
    'pages' => [
        'settings' => [
            'tab' => 'Notifikasi',
            'heading' => 'Urus akaun dan notifikasi anda di satu tempat.',
            'description' => 'Kemaskini maklumat akaun, tentukan cara notifikasi dihantar, dan semak saluran yang sudah sedia digunakan.',
            'delivery_heading' => 'Tetapan penghantaran',
            'delivery_description' => 'Tentukan bila notifikasi boleh dihantar, bagaimana ringkasan dijadualkan, dan saluran mana yang patut dicuba dahulu.',
            'timezone_label' => 'Zon waktu jadual',
            'language_label' => 'Bahasa notifikasi',
            'fallback_label' => 'Jika saluran pilihan tidak tersedia',
            'digest_time_label' => 'Waktu ringkasan dihantar',
            'digest_day_label' => 'Hari ringkasan mingguan',
            'quiet_hours_start_label' => 'Mula waktu senyap',
            'quiet_hours_end_label' => 'Tamat waktu senyap',
            'preferred_channels_label' => 'Turutan saluran pilihan',
            'preferred_channels_description' => 'Tetapkan turutan saluran untuk notifikasi segera. Ruang kosong akan diabaikan.',
            'fallback_channels_label' => 'Saluran sandaran',
            'fallback_channels_description' => 'Pilih saluran sandaran yang patut dicuba jika saluran pilihan tidak berjaya digunakan.',
            'channel_slot_label' => 'Pilihan :number',
            'skip_channel_option' => 'Jangan guna ruang ini',
            'urgent_override_label' => 'Benarkan notifikasi penting melepasi waktu senyap',
            'urgent_override_description' => 'Notifikasi penting seperti pembatalan hari yang sama, peringatan 2 jam, dan waktu daftar masuk dibuka masih boleh dihantar ketika waktu senyap.',
            'families_heading' => 'Kategori notifikasi',
            'families_description' => 'Tetapkan kategori utama dahulu, kemudian perincikan notifikasi tertentu di bawahnya.',
            'cadence_label' => 'Kekerapan penghantaran',
            'channels_label' => 'Saluran',
            'trigger_heading' => 'Pelaras mengikut jenis notifikasi',
            'trigger_description' => 'Gunakan bahagian ini jika ada notifikasi tertentu yang perlu berbeza daripada tetapan kategori utamanya.',
            'footer_note' => 'Peranti push diuruskan melalui aplikasi mudah alih. E-mel menggunakan alamat e-mel akaun anda, manakala WhatsApp menggunakan nombor telefon yang telah disahkan.',
            'save_button' => 'Simpan Tetapan Notifikasi',
        ],
        'inbox' => [
            'nav_label' => 'Notifikasi',
            'cta' => 'Buka peti notifikasi',
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
        'empty' => [
            'heading' => 'Belum ada apa-apa untuk disemak',
            'description' => 'Makluman baharu akan muncul di sini apabila sesuatu yang anda ikuti atau jejak memerlukan perhatian anda.',
        ],
    ],
];
