<?php

namespace App\Services\Search\DataRetrieval;

use Illuminate\Database\Eloquent\Collection;

class RankFusionService
{
    private int $k;
    
    public function __construct()
    {
        $this->k = config('text_processing.search.rrf_k', 60);
    }

    public function fuseRankings(array $rankedLists, array $weights = []): Collection
    {
        if (empty($rankedLists)) {
            return new Collection();
        }

        $weights = $weights ?: array_fill(0, count($rankedLists), 1.0);
        $scores = [];

        foreach ($rankedLists as $listIndex => $rankedList) {
            $weight = $weights[$listIndex] ?? 1.0;
            
            foreach ($rankedList as $rank => $item) {
                $rrfScore = $weight * (1 / ($this->k + $rank + 1));
                
                if (!isset($scores[$item->id])) {
                    $scores[$item->id] = ['item' => $item, 'score' => 0, 'ranks' => []];
                }
                
                $scores[$item->id]['score'] += $rrfScore;
                $scores[$item->id]['ranks'][] = [
                    'list' => $listIndex,
                    'rank' => $rank + 1,
                    'weight' => $weight,
                    'rrf_contribution' => $rrfScore
                ];
            }
        }

        uasort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

        return new Collection(collect($scores)->map(function ($data) {
            $data['item']->fusion_score = $data['score'];
            $data['item']->ranking_details = $data['ranks'];
            return $data['item'];
        })->values()->all());
    }

    public function weightedFusion(array $rankedLists, array $weights = []): Collection
    {
        if (empty($rankedLists)) {
            return new Collection();
        }

        $maxRank = max(array_map('count', $rankedLists));
        $scores = [];

        foreach ($rankedLists as $listIndex => $rankedList) {
            $weight = $weights[$listIndex] ?? 1.0;
            
            foreach ($rankedList as $rank => $item) {
                $scores[$item->id] ??= ['item' => $item, 'score' => 0];
                $scores[$item->id]['score'] += $weight * (1 - ($rank / max($maxRank, 1)));
            }
        }

        uasort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

        return new Collection(collect($scores)->map(function ($data) {
            $data['item']->fusion_score = $data['score'];
            return $data['item'];
        })->values()->all());
    }
}