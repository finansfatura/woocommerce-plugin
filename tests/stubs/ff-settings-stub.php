<?php
if (!class_exists('FF_Settings')) {
    /**
     * Test-only replacement: the real FF_Settings reads from the DB option,
     * which we don't have in unit tests. This stub lets MapperTest seed
     * deterministic issuer values.
     */
    class FF_Settings
    {
        public static array $values = [
            'issuer_vkn'        => '1234567890',
            'issuer_title'      => 'Acme A.Ş.',
            'issuer_address'    => 'Test Mah. 1',
            'issuer_tax_office' => 'Beşiktaş',
            'issuer_email'      => 'hello@acme.test',
            'issuer_phone'      => '+905551112233',
        ];

        public static function get(string $key, $default = ''): string
        {
            return isset(self::$values[$key]) ? (string) self::$values[$key] : (string) $default;
        }
    }
}
