<?php

namespace App\Contracts;

interface BankingGatewayInterface
{
    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     short_name: string,
     *     account_min_length: int,
     *     account_max_length: int
     * }>
     */
    public function listSupportedBanks(): array;

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     short_name: string,
     *     account_min_length: int,
     *     account_max_length: int
     * }|null
     */
    public function findBank(string $bankCode): ?array;

    /**
     * @param  array{
     *     bank_code: string,
     *     account_number: string,
     *     account_holder_name: string,
     *     national_id: string,
     *     phone_number: string,
     *     email: ?string,
     *     card_code: string
     * }  $payload
     * @return array{provider_reference: string, otp_channel: string}
     */
    public function initiateAutoDebitLink(array $payload): array;

    /**
     * @return array{confirmed: bool, provider_reference: string}
     */
    public function confirmAutoDebitLink(string $providerReference): array;

    /**
     * @return array{unlinked: bool, provider_reference: string}
     */
    public function unlinkAutoDebit(string $providerReference): array;

    /**
     * @return array{charged: bool, charge_reference: string}
     */
    public function chargeAutoDebit(string $providerReference, int $amountVnd, string $orderCode): array;

    /**
     * @return array{refunded: bool, refund_reference: string}
     */
    public function refundAutoDebit(string $chargeReference, int $amountVnd, string $orderCode): array;
}
