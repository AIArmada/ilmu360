<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Chip\Enums\FpxBank;
use App\Features\DonationChannels\Api\Enums\DonationBankStatus;
use App\Features\DonationChannels\Models\DonationBank;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/**
 * Installs the country bank catalog.
 *
 * Malaysia, Indonesia and Singapore keep their curated domestic payment-rail
 * lists. Malaysia keeps one CHIP FPX identifier per bank and uses the institution
 * names published by Bank Negara Malaysia.
 *
 * Indonesia uses the BIC identifiers of the banks in Bank Indonesia's
 * BI-FAST participant list:
 * https://www.bi.go.id/id/fungsi-utama/sistem-pembayaran/ritel/infrastruktur/Documents/List-Peserta-BI-FAST.pdf
 * Bank Indonesia itself, the KSEI securities depository and the Airpay and
 * Espay/DANA payment operators are participants but not banks, so they are
 * excluded. Syariah business units (UUS) keep their own published BIC.
 *
 * Singapore uses the FAST / GIRO bank codes of the local banks listed in the
 * Monetary Authority of Singapore Financial Institutions Directory. Codes
 * come from the banks' current official ACH / GIRO documentation; each entry
 * cites its source. Bank of Singapore Limited is a private bank without a
 * published FAST / GIRO bank code, so it stays out until an official
 * identifier exists.
 *
 * Every other country is initialized from
 * database/seeders/data/donation-bank-catalogs.json: a filtered snapshot of
 * the MIT-licensed SWIFT/BIC reference data (swiftbic.org 2024 community
 * compilation, plus the PeterNotenboom/SwiftCodes 2015 archive only where the
 * primary source has no records). The snapshot keeps active bank-like
 * institutions, deduplicates each bank inside its country, and records its
 * provenance in `source_url`. Territories without independent BIC records use
 * their parent country's banking system through the catalog reader. The data
 * is reference material for administrator review, not a payment instruction.
 *
 * The `donation_banks` table is the sole runtime source afterwards: this
 * seeder initializes each country once, so it never overwrites administrator
 * edits and never resurrects an entry that was deliberately deleted or
 * retired. Run it again through `db:seed` only to (re)initialize a fresh
 * install.
 */
class DonationBankCatalogSeeder extends Seeder
{
    /**
     * @var array<string, list<array{code: string, name: string, source_url: string}>>|null
     */
    private static ?array $globalCatalogs = null;

    private const string MALAYSIA_SOURCE = 'https://www.bnm.gov.my/regulations/fsp-directory';

    private const string INDONESIA_SOURCE = 'https://www.bi.go.id/id/fungsi-utama/sistem-pembayaran/ritel/infrastruktur/Documents/List-Peserta-BI-FAST.pdf';

    /** @var array<string, string> */
    private const array MALAYSIAN_BANKS = [
        FpxBank::AFFIN_BANK->value => 'Affin Bank Berhad',
        FpxBank::ALLIANCE_BANK->value => 'Alliance Bank Malaysia Berhad',
        FpxBank::AGRONET->value => 'Bank Pertanian Malaysia Berhad (Agrobank)',
        FpxBank::AMBANK->value => 'AmBank (M) Berhad',
        FpxBank::BANK_ISLAM->value => 'Bank Islam Malaysia Berhad',
        FpxBank::BANK_MUAMALAT->value => 'Bank Muamalat Malaysia Berhad',
        FpxBank::BANK_RAKYAT->value => 'Bank Kerjasama Rakyat Malaysia Berhad (Bank Rakyat)',
        FpxBank::BANK_OF_CHINA->value => 'Bank of China (Malaysia) Berhad',
        FpxBank::BNP_PARIBAS->value => 'BNP Paribas Malaysia Berhad',
        FpxBank::BSN->value => 'Bank Simpanan Nasional',
        FpxBank::CIMB_BANK->value => 'CIMB Bank Berhad',
        FpxBank::CITIBANK_CORPORATE_BANKING->value => 'Citibank Berhad',
        FpxBank::DEUTSCHE_BANK->value => 'Deutsche Bank (Malaysia) Berhad',
        FpxBank::HONG_LEONG_BANK->value => 'Hong Leong Bank Berhad',
        FpxBank::HSBC_BANK->value => 'HSBC Bank Malaysia Berhad',
        FpxBank::KFH->value => 'Kuwait Finance House (Malaysia) Berhad',
        FpxBank::MAYBANK2U->value => 'Malayan Banking Berhad',
        FpxBank::MBSB_BANK->value => 'MBSB Bank Berhad',
        FpxBank::OCBC_BANK->value => 'OCBC Bank (Malaysia) Berhad',
        FpxBank::PUBLIC_BANK->value => 'Public Bank Berhad',
        FpxBank::RHB_BANK->value => 'RHB Bank Berhad',
        FpxBank::STANDARD_CHARTERED->value => 'Standard Chartered Bank Malaysia Berhad',
        FpxBank::UOB_BANK->value => 'United Overseas Bank (Malaysia) Berhad',
    ];

    /**
     * BI-FAST participant BIC => display name.
     *
     * @var array<string, string>
     */
    private const array INDONESIA_BANKS = [
        'ABALIDBS' => 'BPD Bali',
        'AGTBIDJA' => 'Bank Raya Indonesia',
        'ALOBIDJA' => 'Allo Bank Indonesia',
        'ANZBIDJX' => 'Bank ANZ Indonesia',
        'ARFAIDJ1' => 'Bank Panin Dubai Syariah',
        'ARTGIDJA' => 'Bank Artha Graha Internasional',
        'AWANIDJA' => 'Bank QNB Indonesia',
        'BBAIIDJA' => 'Bank Bumi Arta',
        'BBBAIDJA' => 'Bank Permata',
        'BBIJIDJA' => 'Bank UOB Indonesia',
        'BBLUIDJA' => 'Bank Digital BCA (blu)',
        'BBUKIDJA' => 'Bank KB Indonesia',
        'BCIAIDJA' => 'Bank Capital Indonesia',
        'BDINIDJA' => 'Bank Danamon Indonesia',
        'BDKIIDJ1' => 'Bank DKI',
        'BIDXIDJA' => 'Bank Index Selindo',
        'BKCHIDJA' => 'Bank of China (Hong Kong)',
        'BKIDIDJA' => 'Bank of India Indonesia',
        'BMRIIDJA' => 'Bank Mandiri',
        'BMSEIDJA' => 'Bank Multiarta Sentosa',
        'BNIAIDJA' => 'Bank CIMB Niaga',
        'BNINIDJA' => 'Bank Negara Indonesia',
        'BNPAIDJA' => 'Bank BNP Paribas Indonesia',
        'BOFAID2X' => 'Bank of America',
        'BOTKIDJX' => 'MUFG Bank',
        'BPIAIDJA' => 'Bank Resona Perdania',
        'BRINIDJA' => 'Bank Rakyat Indonesia',
        'BSDRIDJA' => 'Bank Woori Saudara Indonesia',
        'BSMDIDJA' => 'Bank Syariah Indonesia',
        'BSSPIDSP' => 'BPD Sumsel Babel',
        'BSYAIDJA' => 'Bank BCA Syariah',
        'BTANIDJA' => 'Bank Tabungan Negara',
        'BUMIIDJA' => 'Bank MNC Internasional',
        'BUSTIDJ1' => 'Krom Bank Indonesia',
        'BUTGIDJ1' => 'Bank Mega Syariah',
        'CENAIDJA' => 'Bank Central Asia',
        'CHASIDJX' => 'JPMorgan Chase Bank',
        'CICTIDJA' => 'Bank J Trust Indonesia',
        'CITIIDJX' => 'Citibank',
        'CTCBIDJA' => 'Bank CTBC Indonesia',
        'DBSBIDJA' => 'Bank DBS Indonesia',
        'DEUTIDJA' => 'Deutsche Bank',
        'FAMAIDJ1' => 'Superbank Indonesia',
        'GNESIDJA' => 'Bank Ganesha',
        'HBNIIDJA' => 'Bank Hibank Indonesia',
        'HNBNIDJA' => 'Bank KEB Hana Indonesia',
        'HSBCIDJA' => 'Bank HSBC Indonesia',
        'IAPTIDJA' => 'Bank INA Perdana',
        'IBBKIDJA' => 'Bank Maybank Indonesia',
        'IBKOIDJA' => 'Bank IBK Indonesia',
        'ICBKIDJA' => 'Bank ICBC Indonesia',
        'JAGBIDJA' => 'Bank Jago',
        'JSABIDJ1' => 'Bank Saqu Indonesia',
        'LMANIDJ1' => 'Bank Oke Indonesia',
        'LOMAIDJ1' => 'Bank Amar Indonesia',
        'MASDIDJ1' => 'Bank Maspion Indonesia',
        'MAYAIDJA' => 'Bank Mayapada Internasional',
        'MCORIDJA' => 'Bank China Construction Bank Indonesia',
        'MEDHIDS1' => 'Bank Mestika Dharma',
        'MEEKIDJ1' => 'Bank Shinhan Indonesia',
        'MEGAIDJA' => 'Bank Mega',
        'MHCCIDJA' => 'Bank Mizuho Indonesia',
        'MUABIDJA' => 'Bank Muamalat',
        'NANOIDJ1' => 'Bank Nano Syariah',
        'NETBIDJA' => 'Bank Aladin Syariah',
        'NISPIDJA' => 'Bank OCBC NISP',
        'NOBUIDJA' => 'Bank Nationalnobu',
        'PDBBIDJ1' => 'BPD Banten',
        'PDBKIDJ1' => 'BPD Bengkulu',
        'PDIJIDJ1' => 'BPD Papua',
        'PDJBIDJA' => 'BPD Jawa Barat & Banten',
        'PDJGIDJ1' => 'BPD Jawa Tengah',
        'PDJMIDJ1' => 'BPD Jambi',
        'PDJTIDJ1' => 'BPD Jawa Timur',
        'PDKBIDJ1' => 'BPD Kalimantan Barat',
        'PDKGIDJ1' => 'BPD Kalimantan Tengah',
        'PDKSIDJ1' => 'BPD Kalimantan Selatan',
        'PDKTIDJ1' => 'BPD Kaltim dan Kaltara',
        'PDLPIDJ1' => 'BPD Lampung',
        'PDMLIDJ1' => 'BPD Maluku dan Maluku Utara',
        'PDNBIDJ1' => 'Bank NTB Syariah',
        'PDNTIDJA' => 'BPD NTT',
        'PDRIIDJA' => 'BPD Riau Kepri Syariah',
        'PDSBIDJ1' => 'Bank Nagari',
        'PDSUIDJ1' => 'BPD Sumatera Utara',
        'PDWGIDJ1' => 'BPD Sulawesi Tengah',
        'PDWRIDJ1' => 'BPD Sulawesi Tenggara',
        'PDWSIDJA' => 'Bank Sulselbar',
        'PDWUIDJ1' => 'BPD Sulut Gorontalo',
        'PDYKIDJ1' => 'BPD DIY',
        'PINBIDJA' => 'Panin Bank',
        'PUBAIDJ1' => 'Bank BTPN Syariah',
        'SAHMIDJA' => 'Bank Sahabat Sampoerna',
        'SBIDIDJA' => 'Bank SBI Indonesia',
        'SBJKIDJA' => 'Bank Sinarmas',
        'SCBLIDJX' => 'Standard Chartered Bank',
        'SDOBIDJ1' => 'Bank KB Bukopin Syariah',
        'SIHBIDJ1' => 'Bank Mandiri Taspen',
        'SSPIIDJA' => 'SeaBank Indonesia',
        'SUNIIDJA' => 'Bank SMBC Indonesia',
        'SWAGIDJ1' => 'Bank Syariah Nasional',
        'SYACIDJ1' => 'Bank Aceh Syariah',
        'SYATIDJ1' => 'Bank Jago Syariah (UUS)',
        'SYBBIDJ1' => 'Bank Permata Syariah (UUS)',
        'SYBDIDJ1' => 'Bank Danamon Syariah (UUS)',
        'SYBKIDJ1' => 'Bank Maybank Indonesia Syariah (UUS)',
        'SYDKIDJ1' => 'Bank DKI Syariah (UUS)',
        'SYJBIDJ1' => 'Bank Jabar Banten Syariah',
        'SYJGIDJ1' => 'BPD Jawa Tengah Syariah (UUS)',
        'SYJMIDJ1' => 'BPD Jambi Syariah (UUS)',
        'SYJTIDJ1' => 'BPD Jawa Timur Syariah (UUS)',
        'SYKBIDJ1' => 'BPD Kalimantan Barat Syariah (UUS)',
        'SYKSIDJ1' => 'BPD Kalimantan Selatan Syariah (UUS)',
        'SYKTIDJ1' => 'BPD Kaltim dan Kaltara Syariah (UUS)',
        'SYNAIDJ1' => 'Bank CIMB Niaga Syariah (UUS)',
        'SYONIDJ1' => 'Bank OCBC NISP Syariah (UUS)',
        'SYSBIDJ1' => 'Bank Nagari Syariah (UUS)',
        'SYSSIDJ1' => 'BPD Sumsel Babel Syariah (UUS)',
        'SYSUIDJA' => 'BPD Sumatera Utara Syariah (UUS)',
        'SYWSIDJ1' => 'Bank Sulselbar Syariah (UUS)',
        'SYYKIDJ1' => 'BPD DIY Syariah (UUS)',
        'VICTIDJ1' => 'Bank Victoria Internasional',
        'YUDBIDJ1' => 'Bank Neo Commerce',
    ];

    /**
     * FAST / GIRO bank code => display name.
     *
     * @var array<int, string>
     */
    private const array SINGAPORE_BANKS = [
        '7171' => 'DBS Bank / POSB',
        '7339' => 'OCBC Bank',
        '7375' => 'UOB',
        '9751' => 'GXS Bank',
        '9779' => 'MariBank Singapore',
    ];

    /**
     * @var array<int, string>
     */
    private const array SINGAPORE_SOURCES = [
        '7171' => 'https://www.dbs.com.sg/personal/support/bank-general-bank-branch-names-codes.html',
        '7339' => 'https://www.ocbc.com/assets/pdf/ach-bank-and-branch-code-guide.pdf',
        '7375' => 'https://www.uob.com.sg/assets/pdfs/global/achcode.pdf',
        '9751' => 'https://help.gxs.com.sg/Payments/Send_Money/Can_I_receive_funds_in_my_GXS_Savings_Account_via_GIRO%3F',
        '9779' => 'https://www.maribank.sg/help-centre/article/10459-what-is-maribanks-swift-code-bic-bank-code-or-branch-code',
    ];

    public function run(): void
    {
        $catalogs = [
            'MY' => $this->withSource(self::MALAYSIAN_BANKS, self::MALAYSIA_SOURCE),
            'ID' => $this->withSource(self::INDONESIA_BANKS, self::INDONESIA_SOURCE),
            'SG' => $this->singaporeanBanks(),
        ];

        foreach ($this->globalCatalogs() as $iso2 => $entries) {
            $catalogs[$iso2] ??= $entries;
        }

        $countryIds = AddressCountry::query()
            ->whereIn('iso2', array_keys($catalogs))
            ->pluck('id', 'iso2');

        foreach ($catalogs as $iso2 => $entries) {
            $countryId = $countryIds->get($iso2);

            if (! is_string($countryId)) {
                continue;
            }

            $this->seedCountry($countryId, $entries);
        }
    }

    /**
     * @return array<string, list<array{code: string, name: string, source_url: string}>>
     */
    private function globalCatalogs(): array
    {
        if (self::$globalCatalogs !== null) {
            return self::$globalCatalogs;
        }

        $path = database_path('seeders/data/donation-bank-catalogs.json');

        if (! is_file($path)) {
            return self::$globalCatalogs = [];
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::$globalCatalogs = [];
        }

        $countries = is_array($payload) ? ($payload['countries'] ?? null) : null;

        if (! is_array($countries)) {
            return self::$globalCatalogs = [];
        }

        $catalogs = [];

        foreach ($countries as $iso2 => $catalog) {
            if (! is_string($iso2) || ! is_array($catalog)) {
                continue;
            }

            $sourceUrl = is_string($catalog['source_url'] ?? null) ? trim($catalog['source_url']) : '';
            $entries = [];

            foreach (($catalog['banks'] ?? []) as $bank) {
                if (! is_array($bank)) {
                    continue;
                }

                $code = is_string($bank['code'] ?? null) ? strtoupper(trim($bank['code'])) : '';
                $name = is_string($bank['name'] ?? null) ? trim($bank['name']) : '';

                if ($code === '' || $name === '' || $sourceUrl === '') {
                    continue;
                }

                $entries[] = [
                    'code' => $code,
                    'name' => $name,
                    'source_url' => $sourceUrl,
                ];
            }

            if ($entries !== []) {
                $catalogs[strtoupper($iso2)] = $entries;
            }
        }

        return self::$globalCatalogs = $catalogs;
    }

    /**
     * @param  list<array{code: string, name: string, source_url: string}>  $entries
     */
    private function seedCountry(string $countryId, array $entries): void
    {
        DB::transaction(function () use ($countryId, $entries): void {
            $initialized = DB::table('donation_bank_catalog_initializations')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'country_id' => $countryId,
            ]);

            if ($initialized === 0 || DonationBank::query()->where('country_id', $countryId)->exists()) {
                return;
            }

            $now = now();
            $rows = [];
            $seenCodes = [];

            foreach ($entries as $entry) {
                $code = strtoupper(trim((string) ($entry['code'] ?? '')));
                $name = trim((string) ($entry['name'] ?? ''));
                $sourceUrl = trim((string) ($entry['source_url'] ?? ''));

                if ($code === '' || $name === '' || $sourceUrl === '' || isset($seenCodes[$code])) {
                    continue;
                }

                $seenCodes[$code] = true;
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'country_id' => $countryId,
                    'code' => $code,
                    'name' => $name,
                    'source_url' => $sourceUrl,
                    'status' => DonationBankStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('donation_banks')->insert($chunk);
            }
        });
    }

    /**
     * @param  array<string, string>  $banks
     * @return list<array{code: string, name: string, source_url: string}>
     */
    private function withSource(array $banks, string $source): array
    {
        $entries = [];

        foreach ($banks as $code => $name) {
            $entries[] = [
                'code' => $code,
                'name' => $name,
                'source_url' => $source,
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{code: string, name: string, source_url: string}>
     */
    private function singaporeanBanks(): array
    {
        $entries = [];

        foreach (self::SINGAPORE_BANKS as $code => $name) {
            $entries[] = [
                'code' => (string) $code,
                'name' => $name,
                'source_url' => self::SINGAPORE_SOURCES[$code],
            ];
        }

        return $entries;
    }
}
