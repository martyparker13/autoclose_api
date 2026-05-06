<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\DealDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealDocument>
 */
class DealDocumentFactory extends Factory
{
    protected $model = DealDocument::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'deal_id'              => Deal::factory(),
            'type'                 => $this->faker->randomElement(['purchase_agreement', 'financing_contract', 'trade_in_title', 'insurance', 'other']),
            'docusign_envelope_id' => null,
            'docusign_status'      => null,
            's3_path'              => 'documents/' . $this->faker->uuid() . '.pdf',
            'uploaded_by'          => null,
            'signed_at'            => null,
        ];
    }

    public function signed(): static
    {
        return $this->state(fn (array $attrs) => [
            'docusign_envelope_id' => $this->faker->uuid(),
            'docusign_status'      => 'completed',
            'signed_at'            => now(),
        ]);
    }
}
