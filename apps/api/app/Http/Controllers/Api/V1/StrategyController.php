<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Jobs\RunStrategyJob;
use App\Models\Strategy;
use App\Models\StrategyRun;
use App\Services\Strategy\Rules\FieldRegistry;
use App\Services\Strategy\Rules\RuleOperators;
use App\Services\Strategy\Rules\RuleValidator;
use App\Services\Strategy\StrategyRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as ValidationRule;

class StrategyController extends ApiController
{
    /**
     * The field and operator vocabulary, so the rule builder UI does not have
     * to hardcode a copy that can drift from what the validator accepts.
     */
    public function schema()
    {
        return ApiResponse::success([
            'fields' => FieldRegistry::catalog(),
            'operators' => RuleOperators::labels(),
            'operators_by_type' => [
                FieldRegistry::TYPE_NUMBER => RuleOperators::forType(FieldRegistry::TYPE_NUMBER),
                FieldRegistry::TYPE_BOOLEAN => RuleOperators::forType(FieldRegistry::TYPE_BOOLEAN),
            ],
            'limits' => [
                'max_depth' => RuleValidator::MAX_DEPTH,
                'max_conditions' => RuleValidator::MAX_CONDITIONS,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'scope' => ['sometimes', ValidationRule::in(['mine', 'public', 'all'])],
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $user = $request->user();
        $scope = $data['scope'] ?? 'all';

        $query = Strategy::query()->with('user:id,name')->withCount('runs');

        match ($scope) {
            'mine' => $query->where('user_id', $user->id),
            'public' => $query->where('visibility', Strategy::VISIBILITY_PUBLIC),
            default => $query->visibleTo($user),
        };

        $rows = $query->orderByDesc('last_run_at')
            ->orderByDesc('id')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return ApiResponse::success([
            'strategies' => $rows->map(fn (Strategy $s) => $this->present($s, $user))->all(),
        ]);
    }

    public function show(Request $request, Strategy $strategy)
    {
        if (! $this->canView($strategy, $request)) {
            return ApiResponse::error('Strategy not found.', 404);
        }

        $strategy->load('user:id,name');

        return ApiResponse::success([
            'strategy' => $this->present($strategy, $request->user(), withRules: true),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $strategy = Strategy::create($data + ['user_id' => $request->user()->id]);

        return ApiResponse::success(
            ['strategy' => $this->present($strategy, $request->user(), withRules: true)],
            'Strategy created.',
            201,
        );
    }

    public function update(Request $request, Strategy $strategy)
    {
        // A public strategy is readable and copyable by anyone, but only its
        // owner may change it. Non-owners get 404 rather than 403 for a
        // private one so the endpoint does not confirm it exists.
        if (! $strategy->isOwnedBy($request->user())) {
            return $this->canView($strategy, $request)
                ? ApiResponse::error('Only the owner can modify this strategy. Copy it to make your own version.', 403)
                : ApiResponse::error('Strategy not found.', 404);
        }

        $data = $this->validatePayload($request, partial: true);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $strategy->update($data);

        return ApiResponse::success(
            ['strategy' => $this->present($strategy->refresh(), $request->user(), withRules: true)],
            'Strategy updated.',
        );
    }

    public function destroy(Request $request, Strategy $strategy)
    {
        if (! $strategy->isOwnedBy($request->user())) {
            return $this->canView($strategy, $request)
                ? ApiResponse::error('Only the owner can delete this strategy.', 403)
                : ApiResponse::error('Strategy not found.', 404);
        }

        $strategy->delete();

        return ApiResponse::success(null, 'Strategy deleted.');
    }

    /**
     * Fork a strategy into the caller's own list. This is how a user takes a
     * public strategy and adapts it: the copy is private, owned by them, and
     * points back at the original.
     */
    public function copy(Request $request, Strategy $strategy)
    {
        if (! $this->canView($strategy, $request)) {
            return ApiResponse::error('Strategy not found.', 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
        ]);

        $copy = Strategy::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'] ?? "{$strategy->name} (copy)",
            'description' => $strategy->description,
            'visibility' => Strategy::VISIBILITY_PRIVATE,
            'rules' => $strategy->rules,
            'is_active' => true,
            'copied_from_id' => $strategy->id,
        ]);

        return ApiResponse::success(
            ['strategy' => $this->present($copy, $request->user(), withRules: true)],
            'Strategy copied.',
            201,
        );
    }

    /**
     * Queue a run. Scanning every symbol is too slow for a request, so this
     * returns the queued run and the client polls it.
     */
    public function run(Request $request, Strategy $strategy, StrategyRunner $runner)
    {
        if (! $this->canView($strategy, $request)) {
            return ApiResponse::error('Strategy not found.', 404);
        }

        $data = $request->validate([
            'date' => 'sometimes|date_format:Y-m-d',
        ]);

        $date = $data['date'] ?? $runner->latestScanDate();

        if ($date === null) {
            return ApiResponse::error(
                'No feature data exists to scan. Run features:extract first.',
                422,
            );
        }

        $run = StrategyRun::create([
            'strategy_id' => $strategy->id,
            'scan_date' => $date,
            'status' => StrategyRun::STATUS_QUEUED,
        ]);

        RunStrategyJob::dispatch($strategy->id, $run->id);

        return ApiResponse::success(
            ['run' => $this->presentRun($run)],
            'Run queued.',
            202,
        );
    }

    /**
     * Run history for a strategy, newest first.
     */
    public function runs(Request $request, Strategy $strategy)
    {
        if (! $this->canView($strategy, $request)) {
            return ApiResponse::error('Strategy not found.', 404);
        }

        $runs = $strategy->runs()->orderByDesc('id')->limit(20)->get();

        return ApiResponse::success([
            'runs' => $runs->map(fn (StrategyRun $r) => $this->presentRun($r))->all(),
        ]);
    }

    /**
     * The matches from one run, with the explanation trace that justifies each.
     */
    public function runMatches(Request $request, Strategy $strategy, StrategyRun $run)
    {
        if (! $this->canView($strategy, $request) || (int) $run->strategy_id !== (int) $strategy->id) {
            return ApiResponse::error('Run not found.', 404);
        }

        $data = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:200',
        ]);

        $matches = $run->matches()
            ->with('asset:id,symbol,name,sector')
            ->orderBy('symbol')
            ->limit((int) ($data['limit'] ?? 100))
            ->get();

        return ApiResponse::success([
            'run' => $this->presentRun($run),
            'matches' => $matches->map(fn ($m) => [
                'symbol' => $m->symbol,
                'asset' => $m->asset ? [
                    'id' => (int) $m->asset->id,
                    'symbol' => $m->asset->symbol,
                    'name' => $m->asset->name,
                    'sector' => $m->asset->sector,
                ] : null,
                'facts' => $m->facts ?? [],
                'explanation' => $m->explanation ?? [],
            ])->all(),
        ]);
    }

    private function canView(Strategy $strategy, Request $request): bool
    {
        return $strategy->isPublic() || $strategy->isOwnedBy($request->user());
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function validatePayload(Request $request, bool $partial = false)
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => 'sometimes|nullable|string|max:1000',
            'visibility' => ['sometimes', ValidationRule::in([
                Strategy::VISIBILITY_PRIVATE,
                Strategy::VISIBILITY_PUBLIC,
            ])],
            'is_active' => 'sometimes|boolean',
            'rules' => [$required, 'array'],
        ]);

        if (array_key_exists('rules', $data)) {
            $errors = (new RuleValidator)->validate($data['rules']);

            if ($errors !== []) {
                return ApiResponse::error('The rules are not valid.', 422, ['rules' => $errors]);
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Strategy $strategy, $user, bool $withRules = false): array
    {
        $payload = [
            'id' => (int) $strategy->id,
            'name' => $strategy->name,
            'description' => $strategy->description,
            'visibility' => $strategy->visibility,
            'is_active' => (bool) $strategy->is_active,
            'owner' => $strategy->relationLoaded('user') && $strategy->user
                ? ['id' => (int) $strategy->user->id, 'name' => $strategy->user->name]
                : null,
            'is_owner' => $strategy->isOwnedBy($user),
            'copied_from_id' => $strategy->copied_from_id === null ? null : (int) $strategy->copied_from_id,
            'runs_count' => $strategy->runs_count !== null ? (int) $strategy->runs_count : null,
            'last_run_at' => $strategy->last_run_at?->toIso8601String(),
            'last_run_status' => $strategy->last_run_status,
            'last_match_count' => $strategy->last_match_count === null
                ? null
                : (int) $strategy->last_match_count,
        ];

        if ($withRules) {
            $payload['rules'] = $strategy->rules ?? [];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRun(StrategyRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'strategy_id' => (int) $run->strategy_id,
            'scan_date' => $run->scan_date?->toDateString(),
            'status' => $run->status,
            'evaluated_count' => (int) $run->evaluated_count,
            'matched_count' => (int) $run->matched_count,
            'error' => $run->error,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
