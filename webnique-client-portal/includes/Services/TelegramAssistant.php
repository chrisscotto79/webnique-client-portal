<?php
/**
 * Read-only, retrieval-grounded Telegram assistant.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

use WNQ\Models\Client;
use WNQ\Models\ClientPortal;
use WNQ\Models\KnowledgeBase;
use WNQ\Models\Task;

if (!defined('ABSPATH')) {
    exit;
}

final class TelegramAssistant
{
    private const MAX_QUESTION_LENGTH = 1000;
    private const MAX_CONTEXT_LENGTH = 15000;

    public static function invocationQuestion(string $message): ?string
    {
        if (preg_match('/^\s*hey[\s,]+golden\b\s*[:,-]?\s*(.*)$/iu', $message, $matches) !== 1) {
            return null;
        }
        return trim((string)($matches[1] ?? ''));
    }

    public static function answer(string $question): string
    {
        $question = trim(sanitize_textarea_field($question));
        $question = self::clip($question, self::MAX_QUESTION_LENGTH);
        if ($question === '') {
            return "Ask me a read-only question after “Hey Golden:” or use /ask.\n\nExample: Hey Golden: when is Lucas's payment date?";
        }
        if (self::isGreeting($question)) {
            $answer = 'Hi. Ask me about a client payment date, agency tasks, Google Ads performance, reports, requests, or an SOP saved in the AI Knowledge Base.';
            KnowledgeBase::logQuery($question, $answer, '', [], 'greeting');
            return $answer;
        }

        $match = self::matchClient($question);
        if (!empty($match['ambiguous'])) {
            $names = array_map(static fn(array $client): string => self::clientLabel($client), (array)$match['ambiguous']);
            $answer = 'I found more than one possible client. Which one did you mean: ' . implode(', ', array_slice($names, 0, 5)) . '?';
            KnowledgeBase::logQuery($question, $answer, '', ['Client directory'], 'needs_clarification');
            return $answer;
        }

        $client = is_array($match['client'] ?? null) ? $match['client'] : null;
        $knowledge = KnowledgeBase::search($question, 4);
        $sources = [];
        $context = self::buildContext($question, $client, $knowledge, $sources);
        $client_id = sanitize_text_field((string)($client['client_id'] ?? ''));
        $direct_answer = self::directAnswer($question, $client);
        if ($direct_answer !== null) {
            KnowledgeBase::logQuery($question, $direct_answer, $client_id, ['WordPress client billing record'], 'answered_direct');
            return $direct_answer;
        }
        if ($sources === []) {
            $answer = self::missingSourceAnswer($question);
            KnowledgeBase::logQuery($question, $answer, $client_id, [], 'no_source');
            return $answer;
        }

        if (!class_exists(AIEngine::class)) {
            $answer = self::fallbackAnswer($question, $client, $knowledge);
            KnowledgeBase::logQuery($question, $answer, $client_id, $sources, 'ai_unavailable');
            return $answer;
        }

        $result = AIEngine::generate('telegram_assistant', [
            'question' => $question,
            'context_json' => wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'source_list' => $sources !== [] ? implode(', ', array_unique($sources)) : 'No matching source was found',
        ], $client_id, [
            'max_tokens' => 900,
            'temperature' => 0.2,
            'no_cache' => true,
        ]);

        if (!empty($result['success']) && trim((string)($result['content'] ?? '')) !== '') {
            $answer = self::clip(trim(wp_strip_all_tags((string)$result['content'])), 3900);
            KnowledgeBase::logQuery($question, $answer, $client_id, $sources, 'answered');
            return $answer;
        }

        $answer = self::fallbackAnswer($question, $client, $knowledge);
        KnowledgeBase::logQuery($question, $answer, $client_id, $sources, !empty($result['rate_limited']) ? 'rate_limited' : 'ai_error');
        return $answer;
    }

    private static function buildContext(string $question, ?array $client, array $knowledge, array &$sources): array
    {
        $context = [
            'current_date' => current_time('F j, Y'),
            'access_mode' => 'read-only',
        ];

        if ($client !== null) {
            $client_id = sanitize_text_field((string)($client['client_id'] ?? ''));
            $context['matched_client'] = self::safeClientData($client);
            $sources[] = 'WordPress client record';

            if (self::containsAny($question, ['lead', 'job', 'customer', 'revenue', 'profit', 'follow-up', 'follow up', 'crm'])) {
                $context['crm_summary'] = ClientPortal::getCustomerSummary($client_id, true);
                $context['recent_crm_records'] = array_map([self::class, 'safeCrmRecord'], ClientPortal::getCustomers($client_id, 12, true));
                $sources[] = 'Client CRM summary';
                $sources[] = 'Client CRM records';
            }
            if (self::containsAny($question, ['ad', 'ads', 'campaign', 'click', 'impression', 'conversion', 'spend'])) {
                $context['google_ads'] = ClientPortal::getAdsSpendSnapshot($client_id, false);
                unset($context['google_ads']['customer_id'], $context['google_ads']['errors']);
                $sources[] = 'Google Ads reporting data';
            }
            if (self::containsAny($question, ['report', 'seo', 'traffic', 'ranking', 'rankings', 'search console', 'analytics'])) {
                $reports = ClientPortal::getReports($client_id, 3);
                $context['recent_reports'] = array_map(static function (array $report): array {
                    return array_intersect_key($report, array_flip(['id', 'report_type', 'period_start', 'period_end', 'status', 'generated_at']));
                }, $reports);
                if ($reports !== []) {
                    $latest = ClientPortal::getReport((int)$reports[0]['id'], (string)$client_id);
                    if ($latest) {
                        $context['latest_report_summary'] = self::clip(wp_strip_all_tags((string)($latest['summary_html'] ?? '')), 2500);
                    }
                }
                $sources[] = 'SEO OS reports';
            }
            if (self::containsAny($question, ['request', 'website edit', 'support'])) {
                $requests = ClientPortal::getRequests($client_id, 10);
                $context['client_requests'] = array_map(static function (array $request): array {
                    return array_intersect_key($request, array_flip(['request_type', 'title', 'details', 'priority', 'status', 'created_at', 'updated_at']));
                }, $requests);
                $sources[] = 'Client requests';
            }
            if (self::containsAny($question, ['message', 'ticket', 'support conversation', 'support reply'])) {
                $context['support_tickets'] = array_map([self::class, 'safeTicket'], ClientPortal::getTickets($client_id, 8));
                $sources[] = 'Support tickets';
            }
            if (self::containsAny($question, ['task', 'work completed', 'marketing work'])) {
                $context['client_tasks'] = array_map([self::class, 'safeTask'], ClientPortal::getTasks($client_id, 15, true));
                $sources[] = 'Agency tasks';
            }
        } else {
            if (self::containsAny($question, ['payment', 'billing', 'bill', 'invoice', 'due date', 'pay date'])) {
                $context['client_payment_schedule'] = array_map(static function (array $row): array {
                    return [
                        'client' => self::clientLabel($row),
                        'billing_cycle' => sanitize_text_field((string)($row['billing_cycle'] ?? '')),
                        'monthly_rate' => (float)($row['monthly_rate'] ?? 0),
                        'last_payment_date' => sanitize_text_field((string)($row['last_payment_date'] ?? '')),
                        'next_payment_due_date' => sanitize_text_field((string)($row['next_payment_due_date'] ?? '')),
                    ];
                }, array_slice(Client::getAll(), 0, 40));
                $sources[] = 'WordPress client billing records';
            }
            if (self::containsAny($question, ['task', 'overdue', 'due today', 'work'])) {
                $tasks = array_values(array_filter(Task::getAll(), static fn(array $task): bool => ($task['status'] ?? '') !== 'done'));
                $context['agency_tasks'] = array_map([self::class, 'safeTask'], array_slice($tasks, 0, 20));
                $sources[] = 'Agency tasks';
            }
            if (self::containsAny($question, ['ad', 'ads', 'campaign', 'click', 'impression', 'conversion', 'spend', 'threshold'])) {
                $context['client_ads_snapshots'] = [];
                foreach (array_slice(Client::getAll(), 0, 30) as $row) {
                    $row_client_id = sanitize_text_field((string)($row['client_id'] ?? ''));
                    if ($row_client_id === '') {
                        continue;
                    }
                    $snapshot = ClientPortal::getAdsSpendSnapshot($row_client_id, false);
                    if (empty($snapshot['has_linked_account'])) {
                        continue;
                    }
                    unset($snapshot['customer_id'], $snapshot['errors']);
                    $snapshot['client'] = self::clientLabel($row);
                    $context['client_ads_snapshots'][] = $snapshot;
                }
                $sources[] = 'Google Ads reporting data';
            }
            if (self::containsAny($question, ['client list', 'all clients', 'clients do we have', 'active clients'])) {
                $context['active_clients'] = array_map(static fn(array $row): array => [
                    'client' => self::clientLabel($row),
                    'status' => sanitize_text_field((string)($row['status'] ?? '')),
                    'package' => sanitize_text_field((string)($row['tier'] ?? '')),
                ], array_slice(Client::getAll(), 0, 50));
                $sources[] = 'WordPress client directory';
            }
        }

        if ($knowledge !== []) {
            $remaining = 12000;
            $context['knowledge_base'] = [];
            foreach ($knowledge as $item) {
                if ($remaining < 300) {
                    break;
                }
                $excerpt = self::clip((string)($item['content'] ?? ''), min(6500, $remaining));
                $remaining -= strlen($excerpt);
                $context['knowledge_base'][] = [
                    'title' => sanitize_text_field((string)($item['title'] ?? 'Knowledge item')),
                    'category' => sanitize_key((string)($item['category'] ?? 'general')),
                    'content' => $excerpt,
                    'updated_at' => sanitize_text_field((string)($item['updated_at'] ?? '')),
                ];
                $sources[] = 'Knowledge Base: ' . sanitize_text_field((string)($item['title'] ?? 'Knowledge item'));
            }
        }

        $encoded = wp_json_encode($context);
        if (strlen((string)$encoded) > self::MAX_CONTEXT_LENGTH && !empty($context['knowledge_base'])) {
            $context['knowledge_base'] = array_slice($context['knowledge_base'], 0, 2);
            foreach ($context['knowledge_base'] as &$item) {
                $item['content'] = self::clip((string)$item['content'], 4500);
            }
            unset($item);
        }
        return $context;
    }

    private static function matchClient(string $question): array
    {
        $normalized_question = self::normalize($question);
        $question_tokens = array_values(array_filter(explode(' ', $normalized_question), static function (string $token): bool {
            return strlen($token) >= 3 && !in_array($token, ['client', 'payment', 'date', 'when', 'what', 'where', 'does', 'have', 'with', 'golden', 'package'], true);
        }));
        $scored = [];
        foreach (Client::getAll() as $client) {
            $candidate_values = array_filter([
                (string)($client['company'] ?? ''),
                (string)($client['name'] ?? ''),
                (string)($client['client_id'] ?? ''),
                (string)($client['email'] ?? ''),
            ]);
            $score = 0;
            foreach ($candidate_values as $candidate) {
                $normalized_candidate = self::normalize($candidate);
                if ($normalized_candidate === '') {
                    continue;
                }
                if (strlen($normalized_candidate) >= 4 && str_contains(' ' . $normalized_question . ' ', ' ' . $normalized_candidate . ' ')) {
                    $score += 120 + strlen($normalized_candidate);
                }
                $candidate_tokens = explode(' ', $normalized_candidate);
                foreach ($question_tokens as $question_token) {
                    foreach ($candidate_tokens as $candidate_token) {
                        if ($question_token === $candidate_token) {
                            $score += 25;
                        } elseif (strlen($question_token) >= 4 && strlen($candidate_token) >= 4 && (str_starts_with($candidate_token, $question_token) || str_starts_with($question_token, $candidate_token))) {
                            $score += 8;
                        }
                    }
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'client' => $client];
            }
        }
        if ($scored === []) {
            return ['client' => null, 'ambiguous' => []];
        }
        usort($scored, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        $top_score = (int)$scored[0]['score'];
        if ($top_score < 20) {
            return ['client' => null, 'ambiguous' => []];
        }
        $ties = array_values(array_filter($scored, static fn(array $row): bool => (int)$row['score'] === $top_score));
        if (count($ties) > 1) {
            return ['client' => null, 'ambiguous' => array_column($ties, 'client')];
        }
        return ['client' => $scored[0]['client'], 'ambiguous' => []];
    }

    private static function safeClientData(array $client): array
    {
        $services = $client['active_services'] ?? [];
        if (is_string($services)) {
            $decoded = json_decode($services, true);
            $services = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $services)));
        }
        $clean_services = [];
        foreach (is_array($services) ? $services : [] as $key => $value) {
            if (is_scalar($value) && !is_bool($value) && trim((string)$value) !== '') {
                $clean_services[] = sanitize_text_field((string)$value);
            } elseif (is_string($key) && $key !== '' && !empty($value)) {
                $clean_services[] = sanitize_text_field($key);
            }
        }
        return [
            'client_id' => sanitize_text_field((string)($client['client_id'] ?? '')),
            'contact_name' => sanitize_text_field((string)($client['name'] ?? '')),
            'business_name' => sanitize_text_field((string)($client['company'] ?? '')),
            'email' => sanitize_email((string)($client['email'] ?? '')),
            'phone' => sanitize_text_field((string)($client['phone'] ?? '')),
            'website' => esc_url_raw((string)($client['website'] ?? '')),
            'address' => sanitize_text_field((string)($client['business_address'] ?? '')),
            'city' => sanitize_text_field((string)($client['city'] ?? '')),
            'state' => sanitize_text_field((string)($client['state'] ?? '')),
            'status' => sanitize_text_field((string)($client['status'] ?? '')),
            'package' => sanitize_text_field((string)($client['tier'] ?? '')),
            'active_services' => array_values(array_unique($clean_services)),
            'billing' => [
                'billing_email' => sanitize_email((string)($client['billing_email'] ?? '')),
                'billing_cycle' => sanitize_text_field((string)($client['billing_cycle'] ?? '')),
                'monthly_rate' => (float)($client['monthly_rate'] ?? 0),
                'last_payment_date' => sanitize_text_field((string)($client['last_payment_date'] ?? '')),
                'next_payment_due_date' => sanitize_text_field((string)($client['next_payment_due_date'] ?? '')),
                'payment_count' => absint($client['payment_count'] ?? 0),
                'total_collected' => (float)($client['total_collected'] ?? 0),
            ],
        ];
    }

    private static function safeTask(array $task): array
    {
        return array_intersect_key($task, array_flip(['id', 'title', 'description', 'status', 'task_type', 'priority', 'assigned_to', 'due_date', 'client_id', 'completed_at', 'created_at']));
    }

    private static function safeCrmRecord(array $record): array
    {
        return array_intersect_key($record, array_flip([
            'id', 'record_type', 'pipeline_stage', 'name', 'job_address', 'service', 'crew', 'lead_source',
            'status', 'follow_up_date', 'reminder_date', 'job_date', 'completion_date', 'job_count',
            'estimated_value', 'final_value', 'job_cost', 'profit', 'lost_reason', 'created_at', 'updated_at',
        ]));
    }

    private static function safeTicket(array $ticket): array
    {
        $safe = array_intersect_key($ticket, array_flip([
            'ticket_key', 'subject', 'category', 'priority', 'ticket_status', 'created_at', 'updated_at',
        ]));
        $messages = is_array($ticket['messages'] ?? null) ? array_slice($ticket['messages'], -3) : [];
        $safe['recent_messages'] = array_map(static function (array $message): array {
            return array_intersect_key($message, array_flip(['sender_role', 'message', 'status', 'created_at']));
        }, $messages);
        return $safe;
    }

    private static function fallbackAnswer(string $question, ?array $client, array $knowledge): string
    {
        $direct_answer = self::directAnswer($question, $client);
        if ($direct_answer !== null) {
            return $direct_answer;
        }
        if ($knowledge !== []) {
            $item = $knowledge[0];
            return self::clip((string)($item['content'] ?? ''), 3400) . "\n\nSource: Knowledge Base: " . sanitize_text_field((string)($item['title'] ?? 'Knowledge item'));
        }
        return 'I could not find enough verified information in WordPress to answer that. Add the missing information to the AI Knowledge Base or check the client record.';
    }

    private static function missingSourceAnswer(string $question): string
    {
        if (self::containsAny($question, ['sop', 'standard operating procedure', 'process', 'procedure', 'onboarding', 'checklist'])) {
            return 'I do not have a matching SOP saved in WordPress yet. Add it under Golden Web Marketing Portal > AI Knowledge Base, then ask me again.';
        }
        return 'I could not find matching verified information in WordPress. Add the information to the AI Knowledge Base or ask about a specific client, task, report, request, billing date, or Google Ads account.';
    }

    private static function isGreeting(string $question): bool
    {
        return preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening)[!.?\s]*$/iu', trim($question)) === 1;
    }

    private static function directAnswer(string $question, ?array $client): ?string
    {
        if ($client === null || !self::containsAny($question, ['payment', 'billing', 'bill', 'invoice', 'due date', 'pay date'])) {
            return null;
        }
        $due = sanitize_text_field((string)($client['next_payment_due_date'] ?? ''));
        $timestamp = $due !== '' ? strtotime($due) : false;
        $date = $timestamp !== false ? wp_date('F j, Y', $timestamp) : 'not configured in WordPress';
        return self::clientLabel($client) . " has a next payment date of {$date}.\n\nSource: WordPress client billing record.";
    }

    private static function containsAny(string $question, array $needles): bool
    {
        $question = strtolower($question);
        foreach ($needles as $needle) {
            if (str_contains($question, strtolower($needle))) {
                return true;
            }
        }
        return false;
    }

    private static function clientLabel(array $client): string
    {
        return sanitize_text_field((string)(($client['company'] ?? '') ?: ($client['name'] ?? '') ?: ($client['client_id'] ?? 'Client')));
    }

    private static function normalize(string $value): string
    {
        $value = strtolower(remove_accents($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function clip(string $value, int $length): string
    {
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, max(1, $length));
        }
        return substr($value, 0, max(1, $length));
    }
}
