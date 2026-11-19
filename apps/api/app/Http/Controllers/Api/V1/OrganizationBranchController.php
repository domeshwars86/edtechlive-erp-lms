<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\OrganizationBranch;
use App\Services\OrganizationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationBranchController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization);
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = OrganizationBranch::query()->where('organization_id', $organization);
        if (isset($filters['search'])) {
            $search = '%'.mb_strtolower(trim($filters['search'])).'%';
            $query->where(fn (Builder $builder) => $builder->whereRaw('LOWER(name) LIKE ?', [$search])
                ->orWhereRaw('LOWER(code) LIKE ?', [$search]));
        }
        $records = $query->when(array_key_exists('is_active', $filters), fn (Builder $builder) => $builder->where('is_active', $filters['is_active']))
            ->orderBy('name')->paginate(min(max((int) ($filters['per_page'] ?? 50), 1), 100));

        return response()->json(['data' => $records->items(), 'meta' => [
            'current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total(),
        ]]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', Rule::unique('organization_branches', 'name')->where('organization_id', $organization)],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('organization_branches', 'code')->where('organization_id', $organization)],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_email' => ['nullable', 'email', 'max:254'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $branch = OrganizationBranch::query()->create(['organization_id' => $organization] + $data);

        return response()->json(['data' => $branch->refresh()], 201);
    }

    public function update(Request $request, int $organization, int $record): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $branch = OrganizationBranch::query()->where('organization_id', $organization)->findOrFail($record);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120', Rule::unique('organization_branches', 'name')->where('organization_id', $organization)->ignore($branch->id)],
            'code' => ['sometimes', 'nullable', 'string', 'max:32', Rule::unique('organization_branches', 'code')->where('organization_id', $organization)->ignore($branch->id)],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $branch->update($data);

        return response()->json(['data' => $branch->refresh()]);
    }
}
