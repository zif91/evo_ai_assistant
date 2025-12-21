<?php

namespace EvolutionCMS\AiAssistant\Services;

class SeoService
{
    public function __construct(
        protected AiService $aiService,
        protected ResourceService $resourceService
    ) {}

    /**
     * Analyze SEO of a resource
     */
    public function analyze(int $resourceId): array
    {
        $resource = $this->resourceService->get($resourceId);

        if (!$resource) {
            return ['error' => 'Resource not found'];
        }

        $issues = [];
        $score = 100;

        // Check pagetitle
        if (empty($resource['pagetitle'])) {
            $issues[] = [
                'field' => 'pagetitle',
                'severity' => 'error',
                'message' => 'Page title is empty',
                'suggestion' => 'Add a descriptive page title (50-60 characters recommended)',
            ];
            $score -= 20;
        } elseif (strlen($resource['pagetitle']) > 60) {
            $issues[] = [
                'field' => 'pagetitle',
                'severity' => 'warning',
                'message' => 'Page title is too long (' . strlen($resource['pagetitle']) . ' characters)',
                'suggestion' => 'Shorten the title to under 60 characters',
            ];
            $score -= 5;
        } elseif (strlen($resource['pagetitle']) < 30) {
            $issues[] = [
                'field' => 'pagetitle',
                'severity' => 'info',
                'message' => 'Page title might be too short',
                'suggestion' => 'Consider making the title more descriptive (30-60 characters)',
            ];
            $score -= 2;
        }

        // Check description (meta description)
        if (empty($resource['description'])) {
            $issues[] = [
                'field' => 'description',
                'severity' => 'error',
                'message' => 'Meta description is empty',
                'suggestion' => 'Add a meta description (150-160 characters recommended)',
            ];
            $score -= 15;
        } elseif (strlen($resource['description']) > 160) {
            $issues[] = [
                'field' => 'description',
                'severity' => 'warning',
                'message' => 'Meta description is too long (' . strlen($resource['description']) . ' characters)',
                'suggestion' => 'Shorten to under 160 characters',
            ];
            $score -= 5;
        } elseif (strlen($resource['description']) < 120) {
            $issues[] = [
                'field' => 'description',
                'severity' => 'info',
                'message' => 'Meta description might be too short',
                'suggestion' => 'Consider making it more descriptive (120-160 characters)',
            ];
            $score -= 2;
        }

        // Check longtitle (H1)
        if (empty($resource['longtitle'])) {
            $issues[] = [
                'field' => 'longtitle',
                'severity' => 'warning',
                'message' => 'Long title (H1) is empty',
                'suggestion' => 'Add a long title for the page heading',
            ];
            $score -= 10;
        }

        // Check alias
        if (empty($resource['alias'])) {
            $issues[] = [
                'field' => 'alias',
                'severity' => 'warning',
                'message' => 'URL alias is empty',
                'suggestion' => 'Add a SEO-friendly URL alias',
            ];
            $score -= 10;
        } elseif (preg_match('/[A-Z]/', $resource['alias'])) {
            $issues[] = [
                'field' => 'alias',
                'severity' => 'info',
                'message' => 'URL alias contains uppercase characters',
                'suggestion' => 'Use lowercase characters for better SEO',
            ];
            $score -= 2;
        }

        // Check content
        if (empty($resource['content'])) {
            $issues[] = [
                'field' => 'content',
                'severity' => 'error',
                'message' => 'Page content is empty',
                'suggestion' => 'Add meaningful content to the page',
            ];
            $score -= 20;
        } else {
            $wordCount = str_word_count(strip_tags($resource['content']));
            if ($wordCount < 300) {
                $issues[] = [
                    'field' => 'content',
                    'severity' => 'warning',
                    'message' => "Content is short ({$wordCount} words)",
                    'suggestion' => 'Consider adding more content (300+ words recommended)',
                ];
                $score -= 10;
            }
        }

        // Check introtext
        if (empty($resource['introtext'])) {
            $issues[] = [
                'field' => 'introtext',
                'severity' => 'info',
                'message' => 'Introduction text is empty',
                'suggestion' => 'Add an introduction/summary text',
            ];
            $score -= 5;
        }

        return [
            'resource_id' => $resourceId,
            'score' => max(0, $score),
            'grade' => $this->getGrade($score),
            'issues' => $issues,
            'summary' => $this->getSummary($score, count($issues)),
        ];
    }

    /**
     * Generate SEO suggestions using AI
     */
    public function generateSuggestions(int $resourceId, ?string $focusKeyword = null): array
    {
        $resource = $this->resourceService->get($resourceId);

        if (!$resource) {
            return ['error' => 'Resource not found'];
        }

        $analysis = $this->analyze($resourceId);

        $prompt = "Analyze the following page and provide SEO improvement suggestions:\n\n";
        $prompt .= "Title: {$resource['pagetitle']}\n";
        $prompt .= "Long Title: {$resource['longtitle']}\n";
        $prompt .= "Description: {$resource['description']}\n";
        $prompt .= "URL Alias: {$resource['alias']}\n";
        $prompt .= "Content (first 500 chars): " . substr(strip_tags($resource['content'] ?? ''), 0, 500) . "\n\n";

        if ($focusKeyword) {
            $prompt .= "Focus keyword: {$focusKeyword}\n\n";
        }

        $prompt .= "Current SEO Score: {$analysis['score']}/100\n";
        $prompt .= "Issues found: " . count($analysis['issues']) . "\n\n";

        $prompt .= "Please provide:\n";
        $prompt .= "1. Suggested improved page title (50-60 characters)\n";
        $prompt .= "2. Suggested meta description (150-160 characters)\n";
        $prompt .= "3. Suggested long title/H1\n";
        $prompt .= "4. Suggested URL alias (lowercase, hyphen-separated)\n";
        $prompt .= "5. Brief recommendations for content improvement\n";

        $response = $this->aiService->chat($prompt);

        return [
            'resource_id' => $resourceId,
            'current_analysis' => $analysis,
            'suggestions' => $response['content'] ?? '',
            'ai_response' => $response,
        ];
    }

    /**
     * Apply SEO optimizations to a resource
     */
    public function optimize(int $resourceId, array $optimizations): array
    {
        $results = [];

        $allowedFields = ['pagetitle', 'longtitle', 'description', 'alias', 'introtext', 'menutitle'];
        $updates = array_intersect_key($optimizations, array_flip($allowedFields));

        if (!empty($updates)) {
            $resource = $this->resourceService->update($resourceId, $updates);
            if ($resource) {
                $results['resource'] = [
                    'success' => true,
                    'updated_fields' => array_keys($updates),
                ];
            } else {
                $results['resource'] = [
                    'success' => false,
                    'error' => 'Failed to update resource',
                ];
            }
        }

        // Update TV values if provided
        if (!empty($optimizations['tv'])) {
            $results['tv'] = $this->resourceService->updateTvs($resourceId, $optimizations['tv']);
        }

        // Re-analyze after changes
        $results['new_analysis'] = $this->analyze($resourceId);

        return $results;
    }

    /**
     * Get SEO grade based on score
     */
    protected function getGrade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }

    /**
     * Get summary message
     */
    protected function getSummary(int $score, int $issueCount): string
    {
        if ($score >= 90) {
            return 'Excellent! This page is well-optimized for search engines.';
        }
        if ($score >= 80) {
            return 'Good job! Minor improvements could help this page rank better.';
        }
        if ($score >= 70) {
            return "There are {$issueCount} issues to address for better SEO.";
        }
        if ($score >= 60) {
            return "This page needs attention. {$issueCount} issues were found.";
        }
        return "Critical SEO issues found. {$issueCount} problems need to be fixed.";
    }

    /**
     * Bulk analyze multiple resources
     */
    public function bulkAnalyze(array $resourceIds): array
    {
        $results = [];

        foreach ($resourceIds as $id) {
            $results[$id] = $this->analyze($id);
        }

        // Calculate average score
        $scores = array_column($results, 'score');
        $avgScore = count($scores) > 0 ? array_sum($scores) / count($scores) : 0;

        return [
            'resources' => $results,
            'summary' => [
                'total' => count($resourceIds),
                'average_score' => round($avgScore, 1),
                'average_grade' => $this->getGrade((int) $avgScore),
            ],
        ];
    }
}
