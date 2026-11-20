<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Goal;
use App\Models\GoalAlertEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Goals\GoalEvaluator;
use App\Support\Ownership;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Alta y edición con usuario explícito; evaluar métricas no forma parte de guardar el formulario. */
final class GoalService
{
    public function __construct(private readonly GoalEvaluator $evaluator) {}

    public function evaluate(User $user, Goal $goal): ?GoalAlertEvent
    {
        return $this->evaluator->evaluate($user, $goal);
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, Brand $brand, array $data): Goal
    {
        Ownership::assert($brand, $user);
        $goal = new Goal($this->validated($user, $brand, $data));
        $goal->user_id = $user->id;
        $goal->brand_id = $brand->id;
        $goal->save();

        return $goal->fresh();
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, Goal $goal, array $data): Goal
    {
        Ownership::assertAll([$goal, $goal->brand], $user);
        $goal->fill($this->validated($user, $goal->brand, $data, $goal));
        if ($goal->isDirty(['kind', 'metric', 'threshold', 'social_account_id'])) {
            $goal->forceFill(['last_value' => null, 'last_period' => null, 'last_evaluated_at' => null, 'last_expires_at' => null]);
        }
        $goal->save();

        return $goal->fresh();
    }

    public function delete(User $user, Goal $goal): void
    {
        Ownership::assertAll([$goal, $goal->brand], $user);
        $goal->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(User $user, Brand $brand, array $data, ?Goal $current = null): array
    {
        $kind = is_string($data['kind'] ?? null) ? $data['kind'] : '';
        $token = $kind === 'token_expiry';
        $drop = $kind === 'drop';
        $integer = $token || (($data['metric'] ?? null) === 'followers' && ! $drop);
        $min = $integer ? Goal::LIMITS['min_integer'] : Goal::LIMITS['min_decimal'];
        $max = Goal::LIMITS['max'][$kind] ?? Goal::LIMITS['max']['milestone'];
        $requestedAccount = $data['social_account_id'] ?? null;
        $keepingAccount = $current?->social_account_id !== null
            && (is_int($requestedAccount) || is_string($requestedAccount))
            && (string) $requestedAccount === (string) $current->social_account_id;
        $accountRule = Rule::exists('social_accounts', 'id')->where('user_id', $user->id)->where('brand_id', $brand->id);
        if (! $keepingAccount) {
            $accountRule->whereNot('status', SocialAccount::STATUS_DISCONNECTED);
        }
        $valid = Validator::make($data, [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\p{C}]/u'],
            'kind' => ['required', 'string', Rule::in(array_keys(Goal::KINDS))],
            'metric' => [$token ? 'prohibited' : 'required', 'nullable', 'string', Rule::in(array_keys(Goal::METRICS))],
            'threshold' => ['required', $integer ? 'integer' : 'numeric', 'min:'.$min, 'max:'.$max],
            'social_account_id' => [$token ? 'required' : 'nullable', 'integer', $accountRule],
            'enabled' => ['sometimes', 'boolean'],
            'mail_enabled' => ['sometimes', 'boolean'],
        ], ['social_account_id.exists' => 'La cuenta no pertenece a esta marca o está desconectada.'])->validate();
        $valid['name'] = trim($valid['name']);
        $valid['metric'] = $token ? null : $valid['metric'];

        return $valid;
    }
}
