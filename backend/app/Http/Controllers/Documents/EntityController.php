<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Services\Documents\EntityManagement\EntityRegistry;
use Illuminate\Http\JsonResponse;

class EntityController extends Controller
{
    public function __construct(private EntityRegistry $entityRegistry) {}
    
    public function index(): JsonResponse
    {
        $entities = $this->entityRegistry->all()->map(fn($config, $type) => [
            'type' => $type,
            'display_name' => $config['display_name'] ?? ucfirst($type),
            'description' => $config['description'] ?? '',
            'model' => $config['model'],
            'searchable_fields' => $config['searchable_fields'],
        ]);
        
        return response()->json(['entities' => $entities->values(), 'total' => $entities->count()]);
    }
    
    public function show(string $entityType): JsonResponse
    {
        $config = $this->entityRegistry->get($entityType);
        
        if (!$config) {
            return response()->json([
                'error' => 'Entity type not found',
                'entity_type' => $entityType,
                'available_types' => $this->entityRegistry->types(),
            ], 404);
        }
        
        return response()->json(['entity_type' => $entityType, 'configuration' => $config]);
    }
}