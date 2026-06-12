<?php

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // Custom auth messages
    'name' => 'Name',
    'email' => 'Email',
    'password_label' => 'Password',
    'confirm_password' => 'Confirm Password',
    'remember_me' => 'Remember me',
    'forgot_password' => 'Forgot your password?',
    'already_registered' => 'Already registered?',
    'register' => 'Register',
    'login' => 'Log in',
    'logout' => 'Log Out',
    'profile' => 'Profile',
    'terms_privacy_agreement' => 'I agree to the :terms_link and :privacy_link',
    'terms_of_service' => 'Terms of Service',
    'privacy_policy' => 'Privacy Policy',
    'guest_upload_info' => 'You can upload and convert files as a guest, but you\'ll need to :sign_up_link or :log_in_link to download them.',
    'please_login_download' => 'Please log in to download files.',

    // Email confirmation page
    'email_confirmed' => 'Email Confirmed!',
    'email_confirmed_message' => 'Your account is now fully activated and ready to use.',
    'free_credits_added' => 'Free Credits Added!',
    'free_credits_message' => '🎉 <strong>15 free credits</strong> have been added to your account. You can now start converting your documents!',
    'account_status' => 'Account Status',
    'confirmed' => '✓ Confirmed',
    'available_credits' => 'Available Credits',
    'credits_count' => ':count credits',
    'license_type' => 'License Type',
    'free_tier' => 'Free Tier',
    'start_converting' => 'Start Converting Files',
    'conversion_started' => 'Your conversion has been started! You will be redirected to the homepage.',
    'view_profile' => 'View Profile',
    'welcome_message' => 'Welcome to PDF Engine, <strong>:name</strong>!',
    'ready_to_convert' => 'Ready to convert your documents?',

    // Password setup
    'password_setup_intro' => 'Welcome :name! Set a secure password to complete your account setup.',
    'password_setup_intro_no_name' => 'Welcome! Set a secure password to complete your account setup.',
    'password_requirements' => 'Minimum 8 characters',
    'password_placeholder' => 'Enter your password',
    'password_confirmation_placeholder' => 'Confirm your password',
    'set_password' => 'Set Password',
    'already_have_password_login' => 'Already have a password? Log in',
    'reset_password' => 'Reset Password',
    'forgot_password_title' => 'Forgot Password',

    // Confirm password page
    'confirm_password_intro' => 'This is a secure area of the application. Please confirm your password before continuing.',
    'confirm' => 'Confirm',

    // Email verification
    'verify_email' => 'Verify Email',
    'verify_your_email' => 'Verify Your Email',
    'thanks_for_signing_up' => 'Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you?',
    'verification_link_sent' => 'A new verification link has been sent to the email address you provided during registration.',
    'didnt_receive_email' => 'Didn\'t receive the email?',
    'didnt_receive_email_info' => 'If you didn\'t receive the email, we will gladly send you another.',
    'resend_verification_email' => 'Resend Verification Email',
    'log_out' => 'Log Out',
    'back_to_homepage' => 'Back to homepage',

    // Email errors
    'email_cannot_receive' => 'This email address cannot receive messages. Please use a different email address.',
    'email_send_failed' => 'Unable to send verification email. Please try again later or contact support.',
    'unexpected_error' => 'An unexpected error occurred. Please try again later.',

    // Organization enrollment
    'organization_invitation_accepted' => 'Organization Invitation Accepted',
    'organization_invitation_accepted_message' => 'You have been added to <strong>:organization</strong> as :role.',
    'auto_enrolled_title' => 'Automatically Added to Organization',
    'auto_enrolled_message' => 'Your email domain has been verified for the following organization(s). You have been automatically added as a member:',

    // Password reminder banner (for verified email without password)
    'complete_password_reminder' => 'Don\'t forget to create your password to complete your account.',
    'set_password_now' => 'Set password now',
    'dismiss_reminder' => 'Dismiss reminder',

    // Registration success page
    'registration_successful' => 'Registration Successful',
    'welcome_to_app' => 'Welcome!',
    'account_created_successfully' => 'Your account has been created successfully.',
    'email_confirmation_required' => 'Email Confirmation Required',
    'confirmation_email_sent' => 'We\'ve sent a confirmation email to <strong>:email</strong>. Please check your inbox and click the confirmation link to activate your account and receive your free credits.',
    'email_unconfirmed' => 'Email Unconfirmed',
    'credits_after_confirmation' => '0 credits (15 credits after confirmation)',
    'browse_upload_options' => 'Browse Upload Options',
    'didnt_receive_resend' => 'Didn\'t receive the email? Check your spam folder or',
    'resend_confirmation' => 'resend confirmation',

    // Login page
    'welcome_back' => 'Welcome back',
    'login_subtitle' => 'Log in to your account to continue',
    'email_address' => 'Email address',
    'email_placeholder' => 'Enter your email address',
    'password_placeholder' => 'Enter your password',
    'no_account_yet' => 'Don\'t have an account yet?',
    'back_to_login' => 'Back to login',

    // Register page
    'create_account' => 'Create your account',
    'register_subtitle' => 'Start with your free account',
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'first_name_placeholder' => 'John',
    'last_name_placeholder' => 'Doe',
    'email_placeholder_register' => 'john@example.com',
    'password_choose_strong' => 'Choose a strong password',
    'confirm_password_placeholder' => 'Confirm your password',
    'passwords_not_match' => 'Passwords do not match.',
    'terms_agreement' => 'I agree to the',
    'terms_and' => 'and the',
    'create_account_button' => 'Create account',
    'already_have_account' => 'Already have an account?',
    'joining_organization' => 'You are joining',
    'invited_by' => 'Invited by :name as :role',
    'email_locked_invitation' => 'This email address is locked because you were invited to an organization.',

    // Forgot password page
    'check_your_email' => 'Check your email',
    'forgot_password_subtitle' => 'No worries! Enter your email address and we\'ll send you a reset link.',
    'reset_link_sent' => 'We have sent a password reset link to your email address.',
    'send_reset_link' => 'Send reset link',
    'reset_link_sent_to' => 'Password reset link sent to',
    'email_not_received' => 'Email not received? Check your spam folder or',
    'try_again' => 'try again',
    'need_help' => 'Need help? Contact our support team at',

    // Reset password page
    'set_new_password' => 'Set new password',
    'reset_password_subtitle' => 'Choose a strong password for your account',
    'new_password' => 'New password',
    'new_password_placeholder' => 'Enter your new password',
    'confirm_new_password' => 'Confirm password',
    'confirm_new_password_placeholder' => 'Confirm your new password',
    'reset_password_button' => 'Reset password',

    // Passwordless / social login
    'or'                            => 'or',
    'continue_with_google'          => 'Continue with Google',
    'google_oauth_failed'           => 'Google login failed. Please try again or use a different method.',
    'google_oauth_email_not_verified' => 'Your Google account does not have a verified email address. Please verify your email with Google and try again.',
    'login_with_code'               => 'Login with email code',
    'login_code_request_title'      => 'Login without a password',
    'login_code_request_subtitle'   => "We'll send you a one-time code by email.",
    'login_code_send_button'        => 'Send me a code',
    'login_code_verify_title'       => 'Enter your code',
    'login_code_verify_subtitle'    => 'We sent a 6-digit code to :email.',
    'login_code_label'              => 'Login code',
    'login_code_email_subject'      => 'Your login code',
    'login_code_sent_status'        => 'If the email is registered, we have sent a code. Check your inbox.',
    'login_code_too_many_requests'  => 'Too many requests. Try again in :seconds seconds.',
    'login_code_too_many_attempts'  => 'Too many attempts. Try again in a few minutes.',
    'login_code_invalid'            => 'The code is invalid or expired.',
    'login_code_resend'             => 'Send again',
];
