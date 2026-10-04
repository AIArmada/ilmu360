<?php

declare(strict_types=1);

/*
 * Baked JAKIM zone snapshot (community mirror, NOT official JAKIM).
 * Source: https://api.waktusolat.app/zones | Snapshot: 2026-10-03 | Zones: 60
 * Zones rarely change; refresh on unknown-zone 404 (see JakimMirrorProvider).
 * state_defaults: state-capital zones verified via live GPS probes 2026-10-03.
 * Perak bakes PRK01 (Ipoh polygon result) though daerah text lists Ipoh
 * under PRK02; GPS truth wins for consistency with live lookups.
 */

return [
    'snapshot_date' => '2026-10-03',
    'source' => 'https://api.waktusolat.app/zones',
    'zones' => [
        'JHR01' => [
            'state' => 'Johor',
            'districts' => 'Pulau Aur dan Pulau Pemanggil',
        ],
        'JHR02' => [
            'state' => 'Johor',
            'districts' => 'Johor Bahru, Kota Tinggi, Mersing, Kulai',
        ],
        'JHR03' => [
            'state' => 'Johor',
            'districts' => 'Kluang, Pontian',
        ],
        'JHR04' => [
            'state' => 'Johor',
            'districts' => 'Batu Pahat, Muar, Segamat, Gemas Johor, Tangkak',
        ],
        'KDH01' => [
            'state' => 'Kedah',
            'districts' => 'Kota Setar, Kubang Pasu, Pokok Sena (Daerah Kecil)',
        ],
        'KDH02' => [
            'state' => 'Kedah',
            'districts' => 'Kuala Muda, Yan, Pendang',
        ],
        'KDH03' => [
            'state' => 'Kedah',
            'districts' => 'Padang Terap, Sik',
        ],
        'KDH04' => [
            'state' => 'Kedah',
            'districts' => 'Baling',
        ],
        'KDH05' => [
            'state' => 'Kedah',
            'districts' => 'Bandar Baharu, Kulim',
        ],
        'KDH06' => [
            'state' => 'Kedah',
            'districts' => 'Langkawi',
        ],
        'KDH07' => [
            'state' => 'Kedah',
            'districts' => 'Puncak Gunung Jerai',
        ],
        'KTN01' => [
            'state' => 'Kelantan',
            'districts' => 'Bachok, Kota Bharu, Machang, Pasir Mas, Pasir Puteh, Tanah Merah, Tumpat, Kuala Krai, Mukim Chiku',
        ],
        'KTN02' => [
            'state' => 'Kelantan',
            'districts' => 'Gua Musang (Daerah Galas Dan Bertam), Jeli, Jajahan Kecil Lojing',
        ],
        'MLK01' => [
            'state' => 'Melaka',
            'districts' => 'SELURUH NEGERI MELAKA',
        ],
        'NGS01' => [
            'state' => 'Negeri Sembilan',
            'districts' => 'Tampin, Jempol',
        ],
        'NGS02' => [
            'state' => 'Negeri Sembilan',
            'districts' => 'Jelebu, Kuala Pilah, Rembau',
        ],
        'NGS03' => [
            'state' => 'Negeri Sembilan',
            'districts' => 'Port Dickson, Seremban',
        ],
        'PHG01' => [
            'state' => 'Pahang',
            'districts' => 'Pulau Tioman',
        ],
        'PHG02' => [
            'state' => 'Pahang',
            'districts' => 'Kuantan, Pekan, Muadzam Shah',
        ],
        'PHG03' => [
            'state' => 'Pahang',
            'districts' => 'Jerantut, Temerloh, Maran, Bera, Chenor, Jengka',
        ],
        'PHG04' => [
            'state' => 'Pahang',
            'districts' => 'Bentong, Lipis, Raub',
        ],
        'PHG05' => [
            'state' => 'Pahang',
            'districts' => 'Genting Sempah, Janda Baik, Bukit Tinggi',
        ],
        'PHG06' => [
            'state' => 'Pahang',
            'districts' => 'Cameron Highlands, Genting Higlands, Bukit Fraser',
        ],
        'PHG07' => [
            'state' => 'Pahang',
            'districts' => 'Zon Khas Daerah Rompin, (Mukim Rompin, Mukim Endau, Mukim Pontian)',
        ],
        'PLS01' => [
            'state' => 'Perlis',
            'districts' => 'SELURUH NEGERI PERLIS',
        ],
        'PNG01' => [
            'state' => 'Pulau Pinang',
            'districts' => 'SELURUH NEGERI PULAU PINANG',
        ],
        'PRK01' => [
            'state' => 'Perak',
            'districts' => 'Tapah, Slim River, Tanjung Malim',
        ],
        'PRK02' => [
            'state' => 'Perak',
            'districts' => 'Kuala Kangsar, Sg. Siput , Ipoh, Batu Gajah, Kampar',
        ],
        'PRK03' => [
            'state' => 'Perak',
            'districts' => 'Lenggong, Pengkalan Hulu, Grik',
        ],
        'PRK04' => [
            'state' => 'Perak',
            'districts' => 'Temengor, Belum',
        ],
        'PRK05' => [
            'state' => 'Perak',
            'districts' => 'Kg Gajah, Teluk Intan, Bagan Datuk, Seri Iskandar, Beruas, Parit, Lumut, Sitiawan, Pulau Pangkor',
        ],
        'PRK06' => [
            'state' => 'Perak',
            'districts' => 'Selama, Taiping, Bagan Serai, Parit Buntar',
        ],
        'PRK07' => [
            'state' => 'Perak',
            'districts' => 'Bukit Larut',
        ],
        'SBH01' => [
            'state' => 'Sabah',
            'districts' => 'Bahagian Sandakan (Timur), Bukit Garam, Semawang, Temanggong, Tambisan, Bandar Sandakan, Sukau',
        ],
        'SBH02' => [
            'state' => 'Sabah',
            'districts' => 'Beluran, Telupid, Pinangah, Terusan, Kuamut, Bahagian Sandakan (Barat)',
        ],
        'SBH03' => [
            'state' => 'Sabah',
            'districts' => 'Lahad Datu, Silabukan, Kunak, Sahabat, Semporna, Tungku, Bahagian Tawau (Timur)',
        ],
        'SBH04' => [
            'state' => 'Sabah',
            'districts' => 'Bandar Tawau, Balong, Merotai, Kalabakan, Bahagian Tawau (Barat)',
        ],
        'SBH05' => [
            'state' => 'Sabah',
            'districts' => 'Kudat, Kota Marudu, Pitas, Pulau Banggi, Bahagian Kudat',
        ],
        'SBH06' => [
            'state' => 'Sabah',
            'districts' => 'Gunung Kinabalu',
        ],
        'SBH07' => [
            'state' => 'Sabah',
            'districts' => 'Kota Kinabalu, Ranau, Kota Belud, Tuaran, Penampang, Papar, Putatan, Bahagian Pantai Barat',
        ],
        'SBH08' => [
            'state' => 'Sabah',
            'districts' => 'Pensiangan, Keningau, Tambunan, Nabawan, Bahagian Pendalaman (Atas)',
        ],
        'SBH09' => [
            'state' => 'Sabah',
            'districts' => 'Beaufort, Kuala Penyu, Sipitang, Tenom, Long Pasia, Membakut, Weston, Bahagian Pendalaman (Bawah)',
        ],
        'SGR01' => [
            'state' => 'Selangor',
            'districts' => 'Gombak, Petaling, Sepang, Hulu Langat, Hulu Selangor, Shah Alam',
        ],
        'SGR02' => [
            'state' => 'Selangor',
            'districts' => 'Kuala Selangor, Sabak Bernam',
        ],
        'SGR03' => [
            'state' => 'Selangor',
            'districts' => 'Klang, Kuala Langat',
        ],
        'SWK01' => [
            'state' => 'Sarawak',
            'districts' => 'Limbang, Lawas, Sundar, Trusan',
        ],
        'SWK02' => [
            'state' => 'Sarawak',
            'districts' => 'Miri, Niah, Bekenu, Sibuti, Marudi',
        ],
        'SWK03' => [
            'state' => 'Sarawak',
            'districts' => 'Pandan, Belaga, Suai, Tatau, Sebauh, Bintulu',
        ],
        'SWK04' => [
            'state' => 'Sarawak',
            'districts' => 'Sibu, Mukah, Dalat, Song, Igan, Oya, Balingian, Kanowit, Kapit',
        ],
        'SWK05' => [
            'state' => 'Sarawak',
            'districts' => 'Sarikei, Matu, Julau, Rajang, Daro, Bintangor, Belawai',
        ],
        'SWK06' => [
            'state' => 'Sarawak',
            'districts' => 'Lubok Antu, Sri Aman, Roban, Debak, Kabong, Lingga, Engkelili, Betong, Spaoh, Pusa, Saratok',
        ],
        'SWK07' => [
            'state' => 'Sarawak',
            'districts' => 'Serian, Simunjan, Samarahan, Sebuyau, Meludam',
        ],
        'SWK08' => [
            'state' => 'Sarawak',
            'districts' => 'Kuching, Bau, Lundu, Sematan',
        ],
        'SWK09' => [
            'state' => 'Sarawak',
            'districts' => 'Zon Khas (Kampung Patarikan)',
        ],
        'TRG01' => [
            'state' => 'Terengganu',
            'districts' => 'Kuala Terengganu, Marang, Kuala Nerus',
        ],
        'TRG02' => [
            'state' => 'Terengganu',
            'districts' => 'Besut, Setiu',
        ],
        'TRG03' => [
            'state' => 'Terengganu',
            'districts' => 'Hulu Terengganu',
        ],
        'TRG04' => [
            'state' => 'Terengganu',
            'districts' => 'Dungun, Kemaman',
        ],
        'WLY01' => [
            'state' => 'Wilayah Persekutuan',
            'districts' => 'Kuala Lumpur, Putrajaya',
        ],
        'WLY02' => [
            'state' => 'Wilayah Persekutuan',
            'districts' => 'Labuan',
        ],
    ],
    // Zone state names (as spelled in the `zones` entries above) to the
    // `state_defaults` key whose capital coords represent the zone. Used for
    // coordinate-provider fall-through in monthly refresh jobs. WLY02
    // (Labuan) is exempt: it shares the Wilayah Persekutuan name but sits
    // 1500km from KL, so it maps to state key 15 explicitly in code.
    'zone_state_keys' => [
        'Johor' => '01',
        'Kedah' => '02',
        'Kelantan' => '03',
        'Melaka' => '04',
        'Negeri Sembilan' => '05',
        'Pahang' => '06',
        'Pulau Pinang' => '07',
        'Perak' => '08',
        'Perlis' => '09',
        'Selangor' => '10',
        'Terengganu' => '11',
        'Sabah' => '12',
        'Sarawak' => '13',
        'Wilayah Persekutuan' => '14',
    ],
    'state_defaults' => [
        '01' => ['zone' => 'JHR02', 'lat' => 1.4927, 'lng' => 103.7414],
        '02' => ['zone' => 'KDH01', 'lat' => 6.1248, 'lng' => 100.3678],
        '03' => ['zone' => 'KTN01', 'lat' => 6.1256, 'lng' => 102.2381],
        '04' => ['zone' => 'MLK01', 'lat' => 2.1896, 'lng' => 102.2501],
        '05' => ['zone' => 'NGS03', 'lat' => 2.7258, 'lng' => 101.9424],
        '06' => ['zone' => 'PHG02', 'lat' => 3.8077, 'lng' => 103.3260],
        '07' => ['zone' => 'PNG01', 'lat' => 5.4141, 'lng' => 100.3288],
        '08' => ['zone' => 'PRK01', 'lat' => 4.5975, 'lng' => 101.0901],
        '09' => ['zone' => 'PLS01', 'lat' => 6.4414, 'lng' => 100.1986],
        '10' => ['zone' => 'SGR01', 'lat' => 3.0733, 'lng' => 101.5185],
        '11' => ['zone' => 'TRG01', 'lat' => 5.3302, 'lng' => 103.1408],
        '12' => ['zone' => 'SBH07', 'lat' => 5.9804, 'lng' => 116.0735],
        '13' => ['zone' => 'SWK08', 'lat' => 1.5533, 'lng' => 110.3593],
        '14' => ['zone' => 'WLY01', 'lat' => 3.1390, 'lng' => 101.6869],
        '15' => ['zone' => 'WLY02', 'lat' => 5.2831, 'lng' => 115.2308],
        '16' => ['zone' => 'WLY01', 'lat' => 2.9264, 'lng' => 101.6964],
    ],
    'country_default' => ['zone' => 'WLY01', 'lat' => 3.1390, 'lng' => 101.6869],
    // Per-zone representative coordinates (main town of the zone) for
    // coordinate-provider fall-through. State-capital coords serve zones
    // without a row; every zone far from its capital MUST have one, or a
    // mirror outage caches the capital's clocks under the zone's key.
    // Accuracy target ~0.1deg (sub-minute prayer error). Rows marked
    // approx use the nearest confident town.
    'zone_coords' => [
        // Johor (capital Johor Bahru serves JHR02).
        'JHR01' => ['lat' => 2.4490, 'lng' => 104.5190], // Pulau Aur
        'JHR03' => ['lat' => 2.0300, 'lng' => 103.3200], // Kluang
        'JHR04' => ['lat' => 1.8500, 'lng' => 102.9300], // Batu Pahat
        // Kedah (capital Alor Setar serves KDH01).
        'KDH02' => ['lat' => 5.6500, 'lng' => 100.4900], // Sungai Petani
        'KDH03' => ['lat' => 6.2500, 'lng' => 100.6800], // Kuala Nerang
        'KDH04' => ['lat' => 5.6800, 'lng' => 100.9300], // Baling
        'KDH05' => ['lat' => 5.3700, 'lng' => 100.5600], // Kulim
        'KDH06' => ['lat' => 6.3100, 'lng' => 99.8500], // Kuah, Langkawi
        'KDH07' => ['lat' => 5.7900, 'lng' => 100.4300], // Gunung Jerai
        // Kelantan (capital Kota Bharu serves KTN01).
        'KTN02' => ['lat' => 4.8800, 'lng' => 101.9700], // Gua Musang
        // Pahang (capital Kuantan serves PHG02).
        'PHG01' => ['lat' => 2.8200, 'lng' => 104.1700], // Pulau Tioman
        'PHG03' => ['lat' => 3.4500, 'lng' => 102.4200], // Temerloh
        'PHG04' => ['lat' => 3.5200, 'lng' => 101.9100], // Bentong
        'PHG05' => ['lat' => 3.3500, 'lng' => 101.8000], // Genting Sempah
        'PHG06' => ['lat' => 4.4700, 'lng' => 101.3800], // Tanah Rata
        'PHG07' => ['lat' => 2.9000, 'lng' => 103.4900], // Kuala Rompin
        // Perak (capital Ipoh serves PRK02).
        'PRK01' => ['lat' => 4.2000, 'lng' => 101.2600], // Tapah
        'PRK03' => ['lat' => 5.4300, 'lng' => 101.1300], // Grik
        'PRK04' => ['lat' => 5.6000, 'lng' => 101.3800], // Belum (approx)
        'PRK05' => ['lat' => 4.0200, 'lng' => 101.0200], // Teluk Intan
        'PRK06' => ['lat' => 4.8500, 'lng' => 100.7400], // Taiping
        'PRK07' => ['lat' => 4.8600, 'lng' => 100.7900], // Bukit Larut
        // Sabah (capital Kota Kinabalu serves SBH07).
        'SBH01' => ['lat' => 5.8400, 'lng' => 118.1200], // Sandakan
        'SBH02' => ['lat' => 5.8800, 'lng' => 117.5700], // Beluran
        'SBH03' => ['lat' => 5.0300, 'lng' => 118.3400], // Lahad Datu
        'SBH04' => ['lat' => 4.2400, 'lng' => 117.8900], // Tawau
        'SBH05' => ['lat' => 6.8800, 'lng' => 116.8400], // Kudat
        'SBH06' => ['lat' => 6.0100, 'lng' => 116.5500], // Kinabalu Park HQ
        'SBH08' => ['lat' => 5.3400, 'lng' => 116.1600], // Keningau
        'SBH09' => ['lat' => 5.3500, 'lng' => 115.7500], // Beaufort
        // Sarawak (capital Kuching serves SWK08).
        'SWK01' => ['lat' => 4.7500, 'lng' => 115.0100], // Limbang
        'SWK02' => ['lat' => 4.4000, 'lng' => 113.9900], // Miri
        'SWK03' => ['lat' => 3.1700, 'lng' => 113.0400], // Bintulu
        'SWK04' => ['lat' => 2.2900, 'lng' => 111.8300], // Sibu
        'SWK05' => ['lat' => 2.1300, 'lng' => 111.5200], // Sarikei
        'SWK06' => ['lat' => 1.2400, 'lng' => 111.4600], // Sri Aman
        'SWK07' => ['lat' => 1.4600, 'lng' => 110.4900], // Samarahan
        'SWK09' => ['lat' => 4.8600, 'lng' => 115.4000], // Lawas area (approx)
        // Selangor (capital Shah Alam serves SGR01).
        'SGR02' => ['lat' => 3.3400, 'lng' => 101.2500], // Kuala Selangor
        'SGR03' => ['lat' => 3.0400, 'lng' => 101.4500], // Klang
        // Terengganu (capital Kuala Terengganu serves TRG01).
        'TRG02' => ['lat' => 5.7400, 'lng' => 102.4900], // Jerteh
        'TRG03' => ['lat' => 5.0700, 'lng' => 103.0200], // Kuala Berang
        'TRG04' => ['lat' => 4.7600, 'lng' => 103.4200], // Dungun
        // Negeri Sembilan (capital Seremban serves NGS03).
        'NGS01' => ['lat' => 2.4700, 'lng' => 102.2300], // Tampin
        'NGS02' => ['lat' => 2.7400, 'lng' => 102.2500], // Kuala Pilah
        // Labuan town (replaces the WLY02 special case below when present).
        'WLY02' => ['lat' => 5.2800, 'lng' => 115.2400], // Labuan
    ],
];
