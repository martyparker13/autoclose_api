<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'deal_id'              => $this->deal_id,
            'type'                 => $this->type,
            'docusign_envelope_id' => $this->docusign_envelope_id,
            'docusign_status'      => $this->docusign_status,
            's3_path'              => $this->s3_path,
            'uploaded_by'          => $this->uploaded_by,
            'signed_at'            => $this->signed_at?->toISOString(),
            'created_at'           => $this->created_at?->toISOString(),
            'updated_at'           => $this->updated_at?->toISOString(),
        ];
    }
}
