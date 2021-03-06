<?php

namespace App\Http\Controllers\Auth;

use App\Events\Auth\SocialLogin;
use App\Http\Controllers\Controller;
use App\Models\Auth\User\SocialAccount;
use App\Models\Auth\User\User;
use App\Repositories\SocialAccountRepository;
use App\Repositories\UserRepository;
use Illuminate\Foundation\Auth\RedirectsUsers;
use Socialite;

class SocialLoginController extends Controller
{
    use RedirectsUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/';
    protected $userRepository;
    protected $socialAccountRepository;

    /**
     * SocialLoginController constructor.
     * @param UserRepository $userRepository
     * @param SocialAccountRepository $socialAccountRepository
     */
    public function __construct(UserRepository $userRepository, SocialAccountRepository $socialAccountRepository)
    {
        $this->userRepository = $userRepository;
        $this->socialAccountRepository = $socialAccountRepository;
    }

    /**
     * Get the guard to be used during socail login.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return \Auth::guard();
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array $data
     * @return User
     */
    protected function create(array $data)
    {
        return $this->userRepository->createUser($data);
    }

    /**
     * Redirect user to provider
     *
     * @param $provider
     * @return mixed
     */
    public function redirect($provider)
    {
        $socialite = Socialite::driver($provider);

        $scopes = config('services.' . $provider . '.scopes');
        $with = config('services.' . $provider . '.with');
        $fields = config('services.' . $provider . '.fields');

        if ($scopes) $socialite->scopes($scopes);
        if ($with) $socialite->with($with);
        if ($fields) $socialite->fields($fields);

        return $socialite->redirect();
    }

    /**
     * Social login
     */
    public function login($provider)
    {
        $socialite = Socialite::driver($provider);

        $socialUser = $socialite->user();

        $user = null;

        $account = $this->socialAccountRepository->getSocialAccountByProvider($provider, $socialUser->id);

        if ($account) {

            $account->token = $socialUser->token;
            $account->avatar = $socialUser->avatar;
            $account->save();

            $user = $account->user;
        }

        if (!$user) {

            $account = new SocialAccount([
                'provider' => $provider,
                'provider_id' => $socialUser->id,
                'token' => $socialUser->token,
                'avatar' => $socialUser->avatar,
            ]);

            // User email may not provided.
            $email = $socialUser->email ?: $socialUser->id . '@' . $provider . '.com';

            $user = $this->userRepository->getUserByEmail($email);

            if (!$user) $user = $this->create(['name' => $socialUser->name, 'email' => $email]);

            $account->user()->associate($user);
            $account->save();
        }

        //disable login with social auth form admin
        if (config('auth.socialite.except_roles') && $user->hasRoles(config('auth.socialite.except_roles'))) {
            return redirect(route('login'))->withErrors([app(LoginController::class)->username() => __('auth.social')]);
        }

        session([config('auth.socialite.session_name') => $provider]);

        //fire social login event
        event(new SocialLogin($user, $provider, $socialite));

        $this->guard()->login($user);

        return $this->sendLoginResponse();
    }

    /**
     * Send the response after the user was social authenticated.
     *
     * @return \Illuminate\Http\Response
     */
    protected function sendLoginResponse()
    {
        return $this->socialAuthenticated($this->guard()->user())
            ?: redirect()->intended($this->redirectPath());
    }

    /**
     * The user has been authenticated by social.
     *
     * @param  mixed $user
     * @return mixed
     */
    protected function socialAuthenticated($user)
    {

    }
}
