<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Valida email y contrasena SIN abrir sesion (el controlador decide entre login directo o reto 2FA).
     * Mismo throttleKey y mismos mensajes que el login clasico.
     *
     * @throws ValidationException
     */
    public function validateCredentials(): User
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');
        $provider = Auth::guard()->getProvider();

        // Igual que SessionGuard::attempt: el Timebox iguala el tiempo con usuario existente e inexistente
        // (evita la enumeracion por tiempo) y se rehashea la contrasena si las rondas cambiaron.
        $user = app(Timebox::class)->call(function ($timebox) use ($provider, $credentials) {
            $user = $provider->retrieveByCredentials($credentials);

            if ($user instanceof User && $provider->validateCredentials($user, $credentials)) {
                if (config('hashing.rehash_on_login', true)) {
                    $provider->rehashPasswordIfRequired($user, $credentials);
                }

                $timebox->returnEarly();

                return $user;
            }

            return null;
        }, (int) config('auth.timebox_duration', 200000));

        if (! $user instanceof User) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
