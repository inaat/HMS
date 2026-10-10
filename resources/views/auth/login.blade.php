@extends('layouts.auth2')
@section('title', __('lang_v1.login'))
@inject('request', 'Illuminate\Http\Request')
@section('content')
    @php
        $username = old('username');
        $password = null;
        if (config('app.env') == 'demo') {
            $username = 'admin';
            $password = '123456';

            $demo_types = [
                'all_in_one' => 'admin',
                'super_market' => 'admin',
                'pharmacy' => 'admin-pharmacy',
                'electronics' => 'admin-electronics',
                'services' => 'admin-services',
                'restaurant' => 'admin-restaurant',
                'superadmin' => 'superadmin',
                'woocommerce' => 'woocommerce_user',
                'essentials' => 'admin-essentials',
                'manufacturing' => 'manufacturer-demo',
            ];

            if (!empty($_GET['demo_type']) && array_key_exists($_GET['demo_type'], $demo_types)) {
                $username = $demo_types[$_GET['demo_type']];
            }
        }
    @endphp
    <div class="row">
        <div class="col-md-4">
        @if (config('app.env') == 'demo')
        
                @component('components.widget', [
                    'class' => 'box-primary',
                    'header' =>
                        '<h4 class="text-center">Demo Shops <small><i> <br/>Demos are for example purpose only, this application <u>can be used in many other similar businesses.</u></i> <br/><b>Click button to login that business</b></small></h4>',
                ])
                    <a href="?demo_type=all_in_one" class="btn btn-app bg-olive demo-login" data-toggle="tooltip"
                        title="Showcases all feature available in the application."
                        data-admin="{{ $demo_types['all_in_one'] }}"> <i class="fas fa-star"></i> All In One</a>

                    <a href="?demo_type=pharmacy" class="btn bg-maroon btn-app demo-login" data-toggle="tooltip"
                        title="Shops with products having expiry dates." data-admin="{{ $demo_types['pharmacy'] }}"><i
                            class="fas fa-medkit"></i>Pharmacy</a>

                    <a href="?demo_type=services" class="btn bg-orange btn-app demo-login" data-toggle="tooltip"
                        title="For all service providers like Web Development, Restaurants, Repairing, Plumber, Salons, Beauty Parlors etc."
                        data-admin="{{ $demo_types['services'] }}"><i class="fas fa-wrench"></i>Multi-Service Center</a>

                    <a href="?demo_type=electronics" class="btn bg-purple btn-app demo-login" data-toggle="tooltip"
                        title="Products having IMEI or Serial number code." data-admin="{{ $demo_types['electronics'] }}"><i
                            class="fas fa-laptop"></i>Electronics & Mobile Shop</a>

                    <a href="?demo_type=super_market" class="btn bg-navy btn-app demo-login" data-toggle="tooltip"
                        title="Super market & Similar kind of shops." data-admin="{{ $demo_types['super_market'] }}"><i
                            class="fas fa-shopping-cart"></i> Super Market</a>

                    <a href="?demo_type=restaurant" class="btn bg-red btn-app demo-login" data-toggle="tooltip"
                        title="Restaurants, Salons and other similar kind of shops."
                        data-admin="{{ $demo_types['restaurant'] }}"><i class="fas fa-utensils"></i> Restaurant</a>
                    <hr>

                    <i class="icon fas fa-plug"></i> Premium optional modules:<br><br>

                    <a href="?demo_type=superadmin" class="btn bg-red-active btn-app demo-login" data-toggle="tooltip"
                        title="SaaS & Superadmin extension Demo" data-admin="{{ $demo_types['superadmin'] }}"><i
                            class="fas fa-university"></i> SaaS / Superadmin</a>

                    <a href="?demo_type=woocommerce" class="btn bg-woocommerce btn-app demo-login" data-toggle="tooltip"
                        title="WooCommerce demo user - Open web shop in minutes!!" style="color:white !important"
                        data-admin="{{ $demo_types['woocommerce'] }}"> <i class="fab fa-wordpress"></i> WooCommerce</a>

                    <a href="?demo_type=essentials" class="btn bg-navy btn-app demo-login" data-toggle="tooltip"
                        title="Essentials & HRM (human resource management) Module Demo" style="color:white !important"
                        data-admin="{{ $demo_types['essentials'] }}">
                        <i class="fas fa-check-circle"></i>
                        Essentials & HRM</a>

                    <a href="?demo_type=manufacturing" class="btn bg-orange btn-app demo-login" data-toggle="tooltip"
                        title="Manufacturing module demo" style="color:white !important"
                        data-admin="{{ $demo_types['manufacturing'] }}">
                        <i class="fas fa-industry"></i>
                        Manufacturing Module</a>

                    <a href="?demo_type=superadmin" class="btn bg-maroon btn-app demo-login" data-toggle="tooltip"
                        title="Project module demo" style="color:white !important"
                        data-admin="{{ $demo_types['superadmin'] }}">
                        <i class="fas fa-project-diagram"></i>
                        Project Module</a>

                    <a href="?demo_type=services" class="btn btn-app demo-login" data-toggle="tooltip"
                        title="Advance repair module demo" style="color:white !important; background-color: #bc8f8f"
                        data-admin="{{ $demo_types['services'] }}">
                        <i class="fas fa-wrench"></i>
                        Advance Repair Module</a>

                    <a href="{{ url('docs') }}" target="_blank" class="btn btn-app" data-toggle="tooltip"
                        title="Advance repair module demo" style="color:white !important; background-color: #2dce89">
                        <i class="fas fa-network-wired"></i>
                        Connector Module / API Documentation</a>
                @endcomponent
            
            
        
    @endif
        </div>
        <div class="col-md-4">
            {{-- Fatoora Now login card (brand colours in layouts/partials/extracss_auth) --}}
            <style>
                .fn-card { background: #fff; border-radius: 22px; padding: 30px 28px 26px; box-shadow: 0 24px 60px rgba(8, 40, 25, .28); max-width: 440px; margin: 0 auto 16px; }
                .fn-card h1 { color: var(--fn-ink); font-size: 22px; font-weight: 800; margin: 0; letter-spacing: -.01em; }
                .fn-card h2 { color: var(--fn-muted); font-size: 14px; font-weight: 500; margin: 6px 0 0; }
                .fn-card .fn-label { color: var(--fn-ink); font-size: 13px; font-weight: 600; }
                .fn-card .fn-input { width: 100%; height: 48px; border: 1.5px solid #D5E2DA; border-radius: 12px; padding: 0 14px; background: var(--fn-surface); color: var(--fn-ink); font-size: 15px; font-weight: 500; outline: none; transition: border-color .15s, box-shadow .15s, background .15s; }
                .fn-card .fn-input::placeholder { color: #8FA398; font-weight: 500; }
                .fn-card .fn-input:focus { border-color: var(--fn-green); background: #fff; box-shadow: 0 0 0 4px rgba(46, 158, 106, .16); }
                .fn-card .has-error .fn-input { border-color: #DC2626; }
                .fn-card .help-block { color: #DC2626; font-size: 13px; margin: 6px 0 0; }
                .fn-card .fn-pass { position: relative; }
                .fn-card .fn-pass .fn-input { padding-right: 48px; }
                .fn-card .show_hide_icon { position: absolute; top: 50%; right: 8px; transform: translateY(-50%); background: none; border: 0; padding: 6px; opacity: .65; }
                .fn-card .show_hide_icon:hover { opacity: 1; }
                .fn-card .fn-link { color: var(--fn-green-700); font-size: 13px; font-weight: 600; }
                .fn-card .fn-link:hover { color: var(--fn-green); text-decoration: underline; }
                .fn-card .tw-dw-checkbox { --chkbg: var(--fn-green); --chkfg: #fff; border-color: #B9CCC1; }
                .fn-card .fn-btn { width: 100%; height: 50px; border: 0; border-radius: 12px; background: var(--fn-green); color: var(--fn-on-brand); font-size: 16px; font-weight: 700; margin-top: 8px; box-shadow: 0 8px 20px rgba(46, 158, 106, .35); transition: background .15s, transform .05s; }
                .fn-card .fn-btn:hover { background: var(--fn-green-700); }
                .fn-card .fn-btn:active { transform: translateY(1px); }
                .fn-card .fn-btn:focus-visible { outline: none; box-shadow: 0 0 0 4px rgba(46, 158, 106, .3); }
                .fn-foot { text-align: center; color: rgba(255, 255, 255, .75); font-size: 12px; }
                @media (max-width: 767px) { .fn-card { padding: 24px 18px 20px; border-radius: 18px; } }
            </style>
            <div class="fn-card">
                <div class="tw-flex tw-flex-col tw-gap-4">
                    <div class="tw-flex tw-items-center tw-flex-col tw-text-center">
                        <h1>@lang('lang_v1.welcome_back')</h1>
                        <h2>@lang('lang_v1.login_to_your') {{ config('app.name', 'Fatoora Now') }}</h2>
                    </div>

                    <form method="POST" action="{{ route('login') }}" id="login-form">
                        {{ csrf_field() }}
                        <div class="form-group has-feedback {{ $errors->has('username') ? ' has-error' : '' }}">
                            <label class="tw-dw-form-control">
                                <div class="tw-dw-label">
                                    <span class="fn-label">@lang('Username')</span>
                                </div>

                                <input class="fn-input" autocomplete="username"
                                    name="username" required autofocus placeholder="@lang('lang_v1.username')"
                                    data-last-active-input="" id="username" type="text" name="username"
                                    value="{{ $username }}" />
                                @if ($errors->has('username'))
                                    <span class="help-block">
                                        <strong>{{ $errors->first('username') }}</strong>
                                    </span>
                                @endif
                            </label>
                        </div>

                        <div class="form-group has-feedback {{ $errors->has('password') ? ' has-error' : '' }}">
                            <label class="tw-dw-form-control">
                                <div class="tw-dw-label">
                                    <span class="fn-label">@lang('Password')</span>
                                    @if (config('app.env') != 'demo')
                                        <a href="{{ route('password.request') }}" class="fn-link"
                                            tabindex="-1">@lang('lang_v1.forgot_your_password')</a>
                                    @endif
                                </div>

                                <div class="fn-pass">
                                <input class="fn-input" autocomplete="current-password"
                                    id="password" type="password" name="password" value="{{ $password }}" required
                                    placeholder="@lang('lang_v1.password')" />
                                <button type="button" id="show_hide_icon" class="show_hide_icon" aria-label="Show password">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-eye tw-w-6" viewBox="0 0 24 24" stroke-width="1.5" stroke="#000000" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                        <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                        <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                                    </svg>
                                </button>
                                </div>
                            </label>
                            @if ($errors->has('password'))
                                <span class="help-block">
                                    <strong>{{ $errors->first('password') }}</strong>
                                </span>
                            @endif
                        </div>


                        <div class="tw-dw-form-control">
                            <label class="tw-dw-cursor-pointer tw-dw-label tw-self-start tw-gap-2">
                                <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}
                                    class="tw-dw-checkbox">
                                <span class="fn-label tw-mt-[0.2rem]" style="font-weight: 500;">@lang('lang_v1.remember_me')</span>
                            </label>
                        </div>
                        @if(config('constants.enable_recaptcha'))
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <div class="g-recaptcha" data-sitekey="{{ config('constants.google_recaptcha_key') }}"></div>
                                        @if ($errors->has('g-recaptcha-response'))
                                            <span class="text-danger">{{ $errors->first('g-recaptcha-response') }}</span>
                                        @endif
                                </div>  
                            </div>
                        </div>
                        @endif
                        <button type="submit" class="fn-btn">@lang('lang_v1.login')</button>
                    </form>

                    <div class="tw-flex tw-items-center tw-flex-col">
                        <!-- Register Url -->

                        @if (!($request->segment(1) == 'business' && $request->segment(2) == 'register'))
                            <!-- Register Url -->
                            @if (config('constants.allow_registration'))
                                <a href="{{ route('business.getRegister') }}@if (!empty(request()->lang)) {{ '?lang=' . request()->lang }} @endif"
                                    class="tw-text-sm tw-font-medium tw-text-gray-500 hover:tw-text-gray-500 tw-mt-2">{{ __('business.not_yet_registered') }}
                                    <span class="fn-link">{{ __('business.register_now') }}</span></a>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
            <div class="fn-foot">© {{ date('Y') }} {{ config('app.name', 'Fatoora Now') }}</div>
        </div>
        <div class="col-md-4"></div>
    </div>

@stop
@section('javascript')
    <script type="text/javascript">
        $(document).ready(function() {
            $('#show_hide_icon').off('click');
            $('.change_lang').click(function() {
                window.location = "{{ route('login') }}?lang=" + $(this).attr('value');
            });
            $('a.demo-login').click(function(e) {
                e.preventDefault();
                $('#username').val($(this).data('admin'));
                $('#password').val("{{ $password }}");
                $('form#login-form').submit();
            });

            $('#show_hide_icon').on('click', function(e) {
            e.preventDefault();
            const passwordInput = $('#password');

            if (passwordInput.attr('type') === 'password') {
                passwordInput.attr('type', 'text');
                $('#show_hide_icon').html('<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-eye-off tw-w-6" viewBox="0 0 24 24" stroke-width="1.5" stroke="#000000" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.585 10.587a2 2 0 0 0 2.829 2.828"/><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87"/><path d="M3 3l18 18"/></svg>');
            }
            else if (passwordInput.attr('type') === 'text') {
                passwordInput.attr('type', 'password');
                $('#show_hide_icon').html('<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-eye tw-w-6" viewBox="0 0 24 24" stroke-width="1.5" stroke="#000000" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/></svg>');
            }
        });
        })
    </script>
@endsection
