<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Baris Bahasa Perkaitan Pengesahan
    |--------------------------------------------------------------------------
    |
    | Baris bahasa berikut mengandungi mesej ralat lalai yang digunakan oleh
    | kelas pengesah. Beberapa peraturan ini mempunyai banyak versi seperti
    | peraturan saiz. Jangan ragu untuk mengubah setiap mesej ini di sini.
    |
    */

    'accepted' => ':attribute mestilah diterima.',
    'accepted_if' => ':attribute mestilah diterima apabila :other adalah :value.',
    'active_url' => ':attribute bukan URL yang sah.',
    'after' => ':attribute mestilah tarikh selepas :date.',
    'after_or_equal' => ':attribute mestilah tarikh selepas atau sama dengan :date.',
    'alpha' => ':attribute hanya boleh mengandungi huruf.',
    'alpha_dash' => ':attribute hanya boleh mengandungi huruf, nombor, sengkang dan garis bawah.',
    'alpha_num' => ':attribute hanya boleh mengandungi huruf dan nombor.',
    'array' => ':attribute mestilah dalam bentuk tatasusunan.',
    'ascii' => ':attribute mestilah mengandungi aksara alfanumerik bait tunggal dan simbol sahaja.',
    'before' => ':attribute mestilah tarikh sebelum :date.',
    'before_or_equal' => ':attribute mestilah tarikh sebelum atau sama dengan :date.',
    'between' => [
        'array' => ':attribute mestilah mengandungi antara :min dan :max item.',
        'file' => ':attribute mestilah antara :min dan :max kilobait.',
        'numeric' => ':attribute mestilah antara :min dan :max.',
        'string' => ':attribute mestilah antara :min dan :max aksara.',
    ],
    'boolean' => ':attribute mestilah benar atau salah.',
    'can' => ':attribute mengandungi nilai yang tidak dibenarkan.',
    'confirmed' => 'Pengesahan :attribute tidak sepadan.',
    'contains' => ':attribute kehilangan nilai yang diperlukan.',
    'current_password' => 'Kata laluan tidak sah.',
    'date' => ':attribute bukan tarikh yang sah.',
    'date_equals' => ':attribute mestilah tarikh yang sama dengan :date.',
    'date_format' => ':attribute tidak sepadan dengan format :format.',
    'decimal' => ':attribute mestilah mempunyai :decimal tempat perpuluhan.',
    'declined' => ':attribute mestilah ditolak.',
    'declined_if' => ':attribute mestilah ditolak apabila :other adalah :value.',
    'different' => ':attribute dan :other mestilah berbeza.',
    'digits' => ':attribute mestilah :digits digit.',
    'digits_between' => ':attribute mestilah antara :min dan :max digit.',
    'dimensions' => ':attribute mempunyai dimensi imej yang tidak sah.',
    'distinct' => ':attribute mempunyai nilai pendua.',
    'doesnt_contain' => ':attribute mestilah tidak mengandungi mana-mana yang berikut: :values.',
    'doesnt_end_with' => ':attribute mestilah tidak berakhir dengan salah satu daripada yang berikut: :values.',
    'doesnt_start_with' => ':attribute mestilah tidak bermula dengan salah satu daripada yang berikut: :values.',
    'email' => ':attribute mestilah alamat e-mel yang sah.',
    'ends_with' => ':attribute mestilah berakhir dengan salah satu daripada yang berikut: :values.',
    'enum' => ':attribute yang dipilih adalah tidak sah.',
    'exists' => ':attribute yang dipilih adalah tidak sah.',
    'extensions' => ':attribute mestilah mempunyai salah satu sambungan berikut: :values.',
    'file' => ':attribute mestilah sebuah fail.',
    'filled' => ':attribute mestilah mempunyai nilai.',
    'gt' => [
        'array' => ':attribute mestilah mempunyai lebih daripada :value item.',
        'file' => ':attribute mestilah lebih besar daripada :value kilobait.',
        'numeric' => ':attribute mestilah lebih besar daripada :value.',
        'string' => ':attribute mestilah lebih besar daripada :value aksara.',
    ],
    'gte' => [
        'array' => ':attribute mestilah mempunyai :value item atau lebih.',
        'file' => ':attribute mestilah lebih besar daripada atau sama dengan :value kilobait.',
        'numeric' => ':attribute mestilah lebih besar daripada atau sama dengan :value.',
        'string' => ':attribute mestilah lebih besar daripada atau sama dengan :value aksara.',
    ],
    'hex_color' => ':attribute mestilah warna heksadesimal yang sah.',
    'image' => ':attribute mestilah imej.',
    'in' => ':attribute yang dipilih adalah tidak sah.',
    'in_array' => ':attribute tidak wujud dalam :other.',
    'integer' => ':attribute mestilah integer.',
    'ip' => ':attribute mestilah alamat IP yang sah.',
    'ipv4' => ':attribute mestilah alamat IPv4 yang sah.',
    'ipv6' => ':attribute mestilah alamat IPv6 yang sah.',
    'json' => ':attribute mestilah rentetan JSON yang sah.',
    'list' => ':attribute mestilah senarai.',
    'lowercase' => ':attribute mestilah huruf kecil.',
    'lt' => [
        'array' => ':attribute mestilah mempunyai kurang daripada :value item.',
        'file' => ':attribute mestilah kurang daripada :value kilobait.',
        'numeric' => ':attribute mestilah kurang daripada :value.',
        'string' => ':attribute mestilah kurang daripada :value aksara.',
    ],
    'lte' => [
        'array' => ':attribute mestilah tidak mempunyai lebih daripada :value item.',
        'file' => ':attribute mestilah kurang daripada atau sama dengan :value kilobait.',
        'numeric' => ':attribute mestilah kurang daripada atau sama dengan :value.',
        'string' => ':attribute mestilah kurang daripada atau sama dengan :value aksara.',
    ],
    'mac_address' => ':attribute mestilah alamat MAC yang sah.',
    'max' => [
        'array' => ':attribute tidak boleh mempunyai lebih daripada :max item.',
        'file' => ':attribute tidak boleh lebih besar daripada :max kilobait.',
        'numeric' => ':attribute tidak boleh lebih besar daripada :max.',
        'string' => ':attribute tidak boleh lebih besar daripada :max aksara.',
    ],
    'max_digits' => ':attribute tidak boleh mempunyai lebih daripada :max digit.',
    'mimes' => ':attribute mestilah fail jenis: :values.',
    'mimetypes' => ':attribute mestilah fail jenis: :values.',
    'min' => [
        'array' => ':attribute mestilah sekurang-kurangnya :min item.',
        'file' => ':attribute mestilah sekurang-kurangnya :min kilobait.',
        'numeric' => ':attribute mestilah sekurang-kurangnya :min.',
        'string' => ':attribute mestilah sekurang-kurangnya :min aksara.',
    ],
    'min_digits' => ':attribute mestilah mempunyai sekurang-kurangnya :min digit.',
    'missing' => ':attribute mestilah hilang.',
    'missing_if' => ':attribute mestilah hilang apabila :other adalah :value.',
    'missing_unless' => ':attribute mestilah hilang melainkan :other adalah :value.',
    'missing_with' => ':attribute mestilah hilang apabila :values hadir.',
    'missing_with_all' => ':attribute mestilah hilang apabila :values hadir.',
    'multiple_of' => ':attribute mestilah gandaan bagi :value.',
    'not_in' => ':attribute yang dipilih adalah tidak sah.',
    'not_regex' => 'Format :attribute adalah tidak sah.',
    'numeric' => ':attribute mestilah nombor.',
    'password' => [
        'letters' => ':attribute mestilah mengandungi sekurang-kurangnya satu huruf.',
        'mixed' => ':attribute mestilah mengandungi sekurang-kurangnya satu huruf besar dan satu huruf kecil.',
        'numbers' => ':attribute mestilah mengandungi sekurang-kurangnya satu nombor.',
        'symbols' => ':attribute mestilah mengandungi sekurang-kurangnya satu simbol.',
        'uncompromised' => ':attribute yang diberikan telah muncul dalam kebocoran data. Sila pilih :attribute yang lain.',
    ],
    'present' => ':attribute mestilah hadir.',
    'present_if' => ':attribute mestilah hadir apabila :other adalah :value.',
    'present_unless' => ':attribute mestilah hadir melainkan :other adalah :value.',
    'present_with' => ':attribute mestilah hadir apabila :values hadir.',
    'present_with_all' => ':attribute mestilah hadir apabila :values hadir.',
    'prohibited' => ':attribute adalah dilarang.',
    'prohibited_if' => ':attribute adalah dilarang apabila :other adalah :value.',
    'prohibited_unless' => ':attribute adalah dilarang melainkan :other ada dalam :values.',
    'prohibits' => ':attribute melarang :other daripada hadir.',
    'regex' => 'Format :attribute adalah tidak sah.',
    'required' => ':Attribute diperlukan.',
    'required_array_keys' => ':attribute mestilah mengandungi entri untuk: :values.',
    'required_if' => ':Attribute diperlukan apabila :other adalah :value.',
    'required_if_accepted' => ':Attribute diperlukan apabila :other diterima.',
    'required_if_declined' => ':Attribute diperlukan apabila :other ditolak.',
    'required_unless' => ':Attribute diperlukan melainkan :other ada dalam :values.',
    'required_with' => ':Attribute diperlukan apabila :values hadir.',
    'required_with_all' => ':Attribute diperlukan apabila :values hadir.',
    'required_without' => ':Attribute diperlukan apabila :values tidak hadir.',
    'required_without_all' => ':Attribute diperlukan apabila tiada satu pun daripada :values hadir.',
    'same' => ':attribute dan :other mestilah sepadan.',
    'size' => [
        'array' => ':attribute mestilah mengandungi :size item.',
        'file' => ':attribute mestilah :size kilobait.',
        'numeric' => ':attribute mestilah :size.',
        'string' => ':attribute mestilah :size aksara.',
    ],
    'starts_with' => ':attribute mestilah bermula dengan salah satu daripada yang berikut: :values.',
    'string' => ':attribute mestilah rentetan.',
    'timezone' => ':attribute mestilah zon masa yang sah.',
    'unique' => ':attribute telah pun diambil.',
    'uploaded' => ':attribute gagal dimuat naik.',
    'uppercase' => ':attribute mestilah huruf besar.',
    'url' => ':attribute mestilah URL yang sah.',
    'uuid' => ':attribute mestilah UUID yang sah.',

    /*
    |--------------------------------------------------------------------------
    | Baris Bahasa Pengesahan Tersuai
    |--------------------------------------------------------------------------
    |
    | Di sini anda boleh menentukan mesej pengesahan tersuai untuk atribut menggunakan
    | konvensyen "attribute.rule" untuk menamakan baris. Ini membolehkan ia pantas untuk
    | menentukan baris bahasa tersuai khusus untuk peraturan atribut tertentu.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'mesej-tersuai',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Atribut Pengesahan Tersuai
    |--------------------------------------------------------------------------
    |
    | Baris bahasa berikut digunakan untuk menukar tempat letak atribut kami
    | dengan sesuatu yang lebih mesra pembaca seperti "Alamat E-Mel" dan bukannya
    | "email". Ini benar-benar membantu kami menjadikan mesej kami lebih ekspresif.
    |
    */

    'attributes' => [
        'title' => 'Tajuk',
        'topics' => 'Topik',
        'start_date' => 'Tarikh',
        'time_option' => 'Masa',
        'event_time' => 'Masa Mula',
        'end_time' => 'Masa Tamat',
        'description' => 'Keterangan',
        'submitter_name' => 'Nama',
        'submitter_email' => 'E-mel',
        'submitter_phone' => 'Telefon',
    ],

];
