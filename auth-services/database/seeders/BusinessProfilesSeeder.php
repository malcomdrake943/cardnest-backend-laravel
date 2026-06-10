<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessProfilesSeeder extends Seeder
{
    /**
     * Seeder for the `business_profiles` and `account_holders` tables.
     * Generated on 2026-06-09 12:07:03 from business_profiles.csv
     * Total rows : 5
     */
    public function run(): void
    {
        $profiles = [
            [
                'id' => 82,
                'user_id' => 150,
                'service_type' => 'card_scan',
                'business_name' => 'CardNest Client Sandbox',
                'business_registration_number' => '456',
                'street' => '123 sabzazar',
                'street_line2' => null,
                'city' => 'lahore',
                'state' => 'punjab',
                'zip_code' => '540000',
                'country' => 'pakistan',
                'email' => 'Dawood24@gmail.com',
                'registration_document_path' => 'http://admin.cardnest.io/storage/business_documents/1755249929_registration_image.png',
                'display_name' => null,
                'display_logo' => null,
                'created_at' => '2025-08-15 09:25:29',
                'updated_at' => '2025-08-15 09:25:29',
                
                // Account holder data
                'account_holder' => [
                    'first_name' => 'dawood',
                    'last_name' => 'ayub',
                    'email' => 'Dawood24@gmail.com',
                    'date_of_birth' => '1986-03-11',
                    'street' => 'House # 18 St # 51/a Ijaz St Tufail Road Lahore',
                    'street_line2' => 'NA',
                    'city' => 'Lahore',
                    'state' => 'punjab',
                    'zip_code' => '54000',
                    'country' => 'Pakistan',
                    'id_type' => 'National ID',
                    'id_number' => '35202212290065',
                    'id_document_path' => 'http://admin.cardnest.io/storage/kyc_documents/1755249929_id_image.png',
                    'created_at' => '2025-08-15 09:25:29',
                    'updated_at' => '2025-08-15 09:25:29',
                ]
            ],
            [
                'id' => 97,
                'user_id' => 173,
                'service_type' => 'card_scan',
                'business_name' => 'LolliCash LLC',
                'business_registration_number' => 'EIN 87-4251935',
                'street' => '4445 Corporation Ln',
                'street_line2' => null,
                'city' => 'Virginia Beach',
                'state' => 'VA',
                'zip_code' => '23462',
                'country' => 'United States of America',
                'email' => 'henrynoah790@gmail.com',
                'registration_document_path' => 'http://admin.cardnest.io/storage/business_documents/1755914603_registration_Notarize LolliCash Virginia Corporation.pdf',
                'display_name' => 'LolliCash Security',
                'display_logo' => 'businesslogo/logo_G5536942984B2978_1756387806.gif',
                'created_at' => '2025-08-23 02:03:23',
                'updated_at' => '2025-08-28 13:30:06',
                
                // Account holder data
                'account_holder' => [
                    'first_name' => 'Dr. Eric',
                    'last_name' => 'Pope',
                    'email' => 'ericpope20@yahoo.com',
                    'date_of_birth' => '1980-01-10',
                    'street' => '11500 Macalpine Ct',
                    'street_line2' => null,
                    'city' => 'Glen Allen',
                    'state' => 'VA',
                    'zip_code' => '23059',
                    'country' => 'USA',
                    'id_type' => 'Driver License',
                    'id_number' => 'A0987654321',
                    'id_document_path' => 'http://admin.cardnest.io/storage/kyc_documents/1755914603_id_Eric Drivers License 2.jpeg',
                    'created_at' => '2025-08-23 02:03:23',
                    'updated_at' => '2025-08-28 13:30:06',
                ]
            ],
            [
                'id' => 115,
                'user_id' => 182,
                'service_type' => 'card_scan',
                'business_name' => 'Ramad Pay Inc',
                'business_registration_number' => '11L-853',
                'street' => '2429 E Franklin Ave S',
                'street_line2' => null,
                'city' => 'Minneapolis',
                'state' => 'Minnesota',
                'zip_code' => '55406',
                'country' => 'United States of America',
                'email' => 'alim@ramadpay.com',
                'registration_document_path' => 'http://admin.cardnest.io/storage/business_documents/1756822275_registration_Certificate of Good Standing - Business Corporation 2025 (2).pdf',
                'display_name' => 'Ramad Pay Inc',
                'display_logo' => 'businesslogo/logo_93500624K6V95758_1769219778.png',
                'created_at' => '2025-09-02 14:11:15',
                'updated_at' => '2026-01-24 01:56:18',
                
                // Account holder data
                'account_holder' => [
                    'first_name' => 'Ali',
                    'last_name' => 'Mohammed',
                    'email' => 'alim@ramadpay.com',
                    'date_of_birth' => '1964-09-24',
                    'street' => '2429 E Franklin Ave S',
                    'street_line2' => null,
                    'city' => 'Minneapolis',
                    'state' => 'Minnesota',
                    'zip_code' => '55406',
                    'country' => 'United States',
                    'id_type' => 'Driver License',
                    'id_number' => 'B902-023-983-511',
                    'id_document_path' => 'http://admin.cardnest.io/storage/kyc_documents/1756822275_id_My MN DL Jun 26, 2022.pdf',
                    'created_at' => '2025-09-02 14:11:15',
                    'updated_at' => '2026-01-24 01:56:18',
                ]
            ],
            [
                'id' => 116,
                'user_id' => 206,
                'service_type' => 'card_scan',
                'business_name' => 'deero services',
                'business_registration_number' => '31000289999798',
                'street' => '7900 Excelsior Boulevard',
                'street_line2' => 'Suite 2014',
                'city' => 'Hopkins',
                'state' => 'Minnesota',
                'zip_code' => '55343',
                'country' => 'United States of America',
                'email' => 'rahman@deeroservices.com',
                'registration_document_path' => 'http://admin.cardnest.io/storage/business_documents/1760015124_registration_Deero Virginia MSB Licence.pdf',
                'display_name' => null,
                'display_logo' => null,
                'created_at' => '2025-10-09 13:05:24',
                'updated_at' => '2025-10-09 13:05:24',
                
                // Account holder data
                'account_holder' => [
                    'first_name' => 'abdirahman',
                    'last_name' => 'ahmed',
                    'email' => 'rahman@deeroservices.com',
                    'date_of_birth' => '1997-01-06',
                    'street' => '5597 seminary rd',
                    'street_line2' => 'apt 807',
                    'city' => 'falls church',
                    'state' => 'virginia',
                    'zip_code' => '22041',
                    'country' => 'United States',
                    'id_type' => 'Driver License',
                    'id_number' => 'f69605484',
                    'id_document_path' => 'http://admin.cardnest.io/storage/kyc_documents/1760015124_id_abdirahman dl.jpeg',
                    'created_at' => '2025-10-09 13:05:24',
                    'updated_at' => '2025-10-09 13:05:24',
                ]
            ],
            [
                'id' => 120,
                'user_id' => 209,
                'service_type' => 'card_scan',
                'business_name' => 'CashGo USA, LLC',
                'business_registration_number' => '331544599',
                'street' => '3060 Michellvlle Road',
                'street_line2' => 'Suite 216',
                'city' => 'Bowie',
                'state' => 'MD',
                'zip_code' => '20716',
                'country' => 'United States of America',
                'email' => 'abebe@cashgousa.com',
                'registration_document_path' => 'https://admin.cardnest.io/storage/business_documents/1767757472_registration_MD Certificate of Good Standing.pdf',
                'display_name' => null,
                'display_logo' => null,
                'created_at' => '2026-01-07 03:44:32',
                'updated_at' => '2026-01-07 03:44:32',
                
                // Account holder data
                'account_holder' => [
                    'first_name' => 'Mr. Abebe',
                    'last_name' => 'Gebru',
                    'email' => 'abebe@cashgousa.com',
                    'date_of_birth' => '1977-06-19',
                    'street' => '3060 Michellvlle Road',
                    'street_line2' => 'Suite 216',
                    'city' => 'Bowie',
                    'state' => 'MD',
                    'zip_code' => '20716',
                    'country' => 'USA',
                    'id_type' => 'Driver License',
                    'id_number' => 'F69645446',
                    'id_document_path' => 'https://admin.cardnest.io/storage/kyc_documents/1767757472_id_Abebe VA Driving License.png',
                    'created_at' => '2026-01-07 03:44:32',
                    'updated_at' => '2026-01-07 03:44:32',
                ]
            ],
        ];

        foreach ($profiles as $profile) {
            $accountHolderData = $profile['account_holder'];
            
            // Insert account holder and get the ID
            $accountHolderId = DB::table('account_holders')->insertGetId($accountHolderData);

            // Insert business profile with reference to the account holder
            DB::table('business_profiles')->insert([
                'id' => $profile['id'],
                'user_id' => $profile['user_id'],
                'service_type' => $profile['service_type'],
                'account_holder_id' => $accountHolderId,
                'email' => $profile['email'],
                'business_name' => $profile['business_name'],
                'business_registration_number' => $profile['business_registration_number'],
                'street' => $profile['street'],
                'street_line2' => $profile['street_line2'],
                'city' => $profile['city'],
                'state' => $profile['state'],
                'zip_code' => $profile['zip_code'],
                'country' => $profile['country'],
                'registration_document_path' => $profile['registration_document_path'],
                'display_name' => $profile['display_name'],
                'display_logo' => $profile['display_logo'],
                'created_at' => $profile['created_at'],
                'updated_at' => $profile['updated_at'],
            ]);
        }
    }
}
