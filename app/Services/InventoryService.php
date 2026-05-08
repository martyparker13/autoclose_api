<?php

namespace App\Services;

use App\Models\Dealer;
use App\Models\Vehicle;
use App\Models\VehicleMedia;
use App\Repositories\VehicleRepositoryInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class InventoryService
{
    /** @var list<string> */
    private const SYNC_ALLOWED_FIELDS = [
        'stock_number', 'year', 'make', 'model', 'trim', 'body_style',
        'exterior_color', 'interior_color', 'mileage', 'condition',
        'price', 'msrp', 'internet_price', 'transmission', 'engine',
        'drivetrain', 'fuel_type', 'doors', 'cylinders', 'status',
        'description', 'carfax_url',
    ];

    /** @var list<string> */
    private const SYNC_VALID_CONDITIONS = ['new', 'used', 'certified'];

    /** @var list<string> */
    private const SYNC_VALID_STATUSES = ['available', 'pending', 'sold', 'hold'];

    /** Fields that VIN decode may fill in automatically when absent from the payload. */
    private const VIN_ENRICHABLE_FIELDS = [
        'make', 'model', 'year', 'trim', 'body_style',
        'drivetrain', 'fuel_type', 'transmission', 'doors', 'cylinders', 'engine',
    ];

    public function __construct(
        private readonly VehicleRepositoryInterface $vehicles,
        private readonly VinDecodeService $vinDecoder,
    ) {}

    /**
     * Return a paginated list of a dealer's vehicles.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(Dealer $dealer, array $filters = []): CursorPaginator
    {
        return $this->vehicles->paginateForDealer($dealer->id, $filters);
    }

    /**
     * Full-text search vehicles for a dealer via Scout/Meilisearch.
     *
     * Falls back to a LIKE query when Scout is not configured.
     *
     * @return \Illuminate\Support\Collection<int, Vehicle>
     */
    public function search(Dealer $dealer, string $query, int $limit = 20): \Illuminate\Support\Collection
    {
        return Vehicle::search($query)
            ->where('dealer_id', $dealer->id)
            ->take($limit)
            ->get();
    }

    /**
     * Fetch a single vehicle belonging to a dealer.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function get(int $vehicleId, Dealer $dealer): Vehicle
    {
        return $this->vehicles->findForDealer($vehicleId, $dealer->id);
    }

    /**
     * Create a new vehicle for a dealer.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Dealer $dealer, array $data): Vehicle
    {
        $data['dealer_id'] = $dealer->id;

        return $this->vehicles->create($data);
    }

    /**
     * Update an existing vehicle.
     *
     * @param  array<string, mixed>  $data
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function update(Vehicle $vehicle, Dealer $dealer, array $data): Vehicle
    {
        if ($vehicle->dealer_id !== $dealer->id) {
            abort(403, 'Vehicle does not belong to this dealer.');
        }

        return $this->vehicles->update($vehicle, $data);
    }

    /**
     * Soft-delete a vehicle.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function delete(Vehicle $vehicle, Dealer $dealer): void
    {
        if ($vehicle->dealer_id !== $dealer->id) {
            abort(403, 'Vehicle does not belong to this dealer.');
        }

        $this->vehicles->delete($vehicle);
    }

    /**
     * Upload and attach a media file to a vehicle.
     */
    public function addMedia(Vehicle $vehicle, UploadedFile $file, string $type = 'photo', ?string $label = null): VehicleMedia
    {
        $path = $file->store("vehicles/{$vehicle->id}/media", 's3');

        $maxOrder = $vehicle->media()->max('sort_order') ?? -1;

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        return $vehicle->media()->create([
            'type'       => $type,
            'url'        => $disk->url($path),
            'sort_order' => $maxOrder + 1,
            'is_primary' => $vehicle->media()->count() === 0,
            'label'      => $label,
        ]);
    }

    /**
     * Reorder media items for a vehicle.
     *
     * @param  array<int, int>  $orderedIds  Media IDs in desired display order
     */
    public function reorderMedia(Vehicle $vehicle, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $mediaId) {
            VehicleMedia::where('id', $mediaId)
                ->where('vehicle_id', $vehicle->id)
                ->update([
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ]);
        }
    }

    /**
     * Delete a single media item.
     */
    public function deleteMedia(VehicleMedia $media): void
    {
        // Strip the public URL base to get S3 path
        $path = parse_url($media->url, PHP_URL_PATH);
        if ($path) {
            Storage::disk('s3')->delete(ltrim($path, '/'));
        }

        $vehicleId = $media->vehicle_id;

        $media->delete();

        // Reassign primary if needed
        $first = VehicleMedia::where('vehicle_id', $vehicleId)
            ->orderBy('sort_order')
            ->first();

        if ($first) {
            $first->update(['is_primary' => true]);
        }
    }

    /**
     * Bulk-import vehicles from a CSV file, upserting by VIN.
     *
     * Returns a summary: [ 'created' => int, 'updated' => int, 'skipped' => int, 'errors' => list<string> ]
     *
     * Expected CSV columns (case-insensitive, trimmed):
     *   vin, make, model, year, trim, mileage, price, status, color, description
     *
     * @return array{ created: int, updated: int, skipped: int, errors: list<string> }
     */
    public function importFromCsv(Dealer $dealer, UploadedFile $file): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Could not read file.']];
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['CSV has no header row.']];
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), $header);
        $row    = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $row++;

            if (count($values) !== count($header)) {
                $errors[] = "Row {$row}: column count mismatch — skipped.";
                $skipped++;
                continue;
            }

            $data = array_combine($header, array_map('trim', $values));

            $vin = $data['vin'] ?? '';
            if (! $vin) {
                $errors[] = "Row {$row}: missing VIN — skipped.";
                $skipped++;
                continue;
            }

            $payload = [
                'dealer_id'   => $dealer->id,
                'vin'         => $vin,
                'make'        => $data['make']        ?? null,
                'model'       => $data['model']       ?? null,
                'year'        => isset($data['year'])  ? (int) $data['year']  : null,
                'trim'        => $data['trim']         ?? null,
                'mileage'     => isset($data['mileage']) ? (int) $data['mileage'] : null,
                'price'       => isset($data['price'])   ? (int) round((float) $data['price']) : null,
                'status'      => $data['status']       ?? 'available',
                'color'       => $data['color']        ?? null,
                'description' => $data['description']  ?? null,
            ];

            $existing = Vehicle::where('dealer_id', $dealer->id)->where('vin', $vin)->first();

            if ($existing) {
                $this->vehicles->update($existing, array_filter($payload, fn ($v) => $v !== null));
                $updated++;
            } else {
                $this->vehicles->create($payload);
                $created++;
            }
        }

        fclose($handle);

        return compact('created', 'updated', 'skipped', 'errors');
    }

    /**
     * Sync one JSON chunk from a DMS feed.
     *
     * @param list<array<string,mixed>> $payload
     * @return array{ created: int, updated: int, skipped: int, errors: list<string>, incoming_vins: list<string>, incoming_stocks: list<string> }
     */
    public function syncFromPayload(Dealer $dealer, array $payload): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $seen = [];
        $incomingVins = [];
        $incomingStocks = [];

        foreach ($payload as $index => $item) {
            if (! is_array($item)) {
                $errors[] = "Item {$index}: not an object.";
                $skipped++;
                continue;
            }

            $vin = isset($item['vin']) ? strtoupper(trim((string) $item['vin'])) : null;
            $stockNumber = isset($item['stock_number']) ? trim((string) $item['stock_number']) : null;

            if (! $vin && ! $stockNumber) {
                $errors[] = "Item {$index}: vin or stock_number is required.";
                $skipped++;
                continue;
            }

            if ($vin && strlen($vin) !== 17) {
                $errors[] = "Item {$index}: VIN must be exactly 17 characters (got \"{$vin}\").";
                $skipped++;
                continue;
            }

            $dedupeKey = $vin ?? $stockNumber;
            if (isset($seen[$dedupeKey])) {
                $errors[] = "Item {$index}: duplicate VIN/stock \"{$dedupeKey}\" in payload.";
                $skipped++;
                continue;
            }
            $seen[$dedupeKey] = true;

            $condition = $item['condition'] ?? 'used';
            if (! in_array($condition, self::SYNC_VALID_CONDITIONS, true)) {
                $condition = 'used';
            }

            $status = $item['status'] ?? 'available';
            if (! in_array($status, self::SYNC_VALID_STATUSES, true)) {
                $status = 'available';
            }

            $attributes = ['dealer_id' => $dealer->id];
            if ($vin) {
                $attributes['vin'] = $vin;
                $incomingVins[] = $vin;
            }
            if ($stockNumber) {
                $attributes['stock_number'] = $stockNumber;
                $incomingStocks[] = $stockNumber;
            }

            $values = ['condition' => $condition, 'status' => $status];
            foreach (self::SYNC_ALLOWED_FIELDS as $field) {
                if ($field === 'condition' || $field === 'status') {
                    continue;
                }
                if (! array_key_exists($field, $item)) {
                    continue;
                }

                if (in_array($field, ['price', 'msrp', 'internet_price'], true)) {
                    $values[$field] = (int) round((float) $item[$field] * 100);
                } else {
                    $values[$field] = $item[$field];
                }
            }

            // VIN decode enrichment — fill missing spec fields when a 17-char VIN is present.
            if ($vin) {
                $decoded = $this->vinDecoder->decode($vin);
                if (! empty($decoded)) {
                    foreach (self::VIN_ENRICHABLE_FIELDS as $field) {
                        // Only apply decoded value when the payload did not already supply it.
                        if (! array_key_exists($field, $values) && isset($decoded[$field])) {
                            $values[$field] = $decoded[$field];
                        }
                    }
                    $values['vin_decoded_at'] = Carbon::now();
                }
            }

            $matchColumn = $vin ? 'vin' : 'stock_number';
            $existing = Vehicle::where('dealer_id', $dealer->id)
                ->where($matchColumn, $attributes[$matchColumn])
                ->withTrashed()
                ->first();

            if ($existing) {
                $existing->restore();
                $existing->fill($values)->save();
                $updated++;
            } else {
                Vehicle::create(array_merge($attributes, $values));
                $created++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'incoming_vins' => array_values(array_unique($incomingVins)),
            'incoming_stocks' => array_values(array_unique($incomingStocks)),
        ];
    }

    /**
     * Enrich a single vehicle by decoding its VIN against the NHTSA vPIC API.
     *
     * Only blank / null fields are overwritten — existing data is never clobbered.
     * Returns the list of field names that were updated, or an empty array if nothing changed.
     *
     * @return list<string>
     */
    public function enrichFromVin(Vehicle $vehicle): array
    {
        if (! $vehicle->vin || strlen($vehicle->vin) !== 17) {
            return [];
        }

        $decoded = $this->vinDecoder->decode($vehicle->vin);

        if (empty($decoded)) {
            return [];
        }

        $applied = [];

        foreach (self::VIN_ENRICHABLE_FIELDS as $field) {
            if (isset($decoded[$field]) && ($vehicle->{$field} === null || $vehicle->{$field} === '')) {
                $vehicle->{$field} = $decoded[$field];
                $applied[] = $field;
            }
        }

        if (! empty($applied)) {
            $vehicle->vin_decoded_at = Carbon::now();
            $vehicle->save();
        }

        return $applied;
    }

    /**
     * Archive (set hold) vehicles that are not present in the latest feed snapshot.
     *
     * @param list<string> $incomingVins
     * @param list<string> $incomingStocks
     */
    public function archiveMissingFromSync(Dealer $dealer, array $incomingVins, array $incomingStocks): int
    {
        $query = Vehicle::where('dealer_id', $dealer->id)
            ->where('status', 'available');

        if (! empty($incomingVins)) {
            $query->whereNotIn('vin', $incomingVins);
        }

        if (! empty($incomingStocks)) {
            $query->whereNotIn('stock_number', $incomingStocks);
        }

        return $query->update(['status' => 'hold']);
    }
}
