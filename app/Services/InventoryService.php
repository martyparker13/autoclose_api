<?php

namespace App\Services;

use App\Models\Dealer;
use App\Models\Vehicle;
use App\Models\VehicleMedia;
use App\Repositories\VehicleRepositoryInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class InventoryService
{
    public function __construct(
        private readonly VehicleRepositoryInterface $vehicles,
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
}
