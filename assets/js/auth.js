/**
 * auth.js
 * Handles Login, Multi-step Registration, and Password Reset UI logic.
 */

/**
 * ── LOGIN ──
 * Authenticates the user and redirects to the appropriate dashboard.
 */
async function handleLogin(e) {
    e.preventDefault();
    clearAlert('alert-box');
    
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;

    if (!email || !password) {
        showAlert('alert-box', 'Please enter both email and password.', 'error');
        return;
    }

    setLoading('login-btn', true, 'Logging in...');
    
    // apiPost handles the BASE path and JSON conversion
    const result = await apiPost('/auth.php?action=login', { email, password });
    
    setLoading('login-btn', false);

    if (result.success) {
        showAlert('alert-box', 'Login successful! Redirecting...', 'success');
        
        // Define dashboard locations based on user roles
        const paths = { 
            admin:    BASE + '/admin/dashboard.html', 
            employer: BASE + '/employer/dashboard.html', 
            seeker:   BASE + '/seeker/dashboard.html' 
        };
        
        // Short delay so the user can see the success message
        setTimeout(() => {
            window.location.href = paths[result.user.role] || BASE + '/index.html';
        }, 800);
    } else {
        showAlert('alert-box', result.error || 'Invalid credentials.', 'error');
    }
}

/**
 * ── REGISTER STEP 1: Send OTP ──
 * Validates inputs and triggers the verification email/OTP generation.
 */
async function handleSendOtp(e) {
    e.preventDefault();
    clearAlert('alert-box');

    const name = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const pass = document.getElementById('password').value;
    const confirm = document.getElementById('confirm-password')?.value;
    const role = document.getElementById('role')?.value || 'seeker';

    // Basic Client-side Validation
    if (!name || !email || !pass) {
        showAlert('alert-box', 'All fields are required.', 'error');
        return;
    }
    if (pass !== confirm) { 
        showAlert('alert-box', 'Passwords do not match.', 'error'); 
        return; 
    }
    if (pass.length < 6) {
        showAlert('alert-box', 'Password must be at least 6 characters.', 'error');
        return;
    }

    setLoading('register-btn', true, 'Sending OTP...');

    const result = await apiPost('/auth.php?action=send-otp', {
        name, email, password: pass, role
    });

    setLoading('register-btn', false);

    if (result.success) {
        // Toggle UI visibility from Registration form to OTP form
        document.getElementById('step-register').style.display = 'none';
        document.getElementById('step-otp').style.display = 'block';

        // Auto-fill OTP if backend is in Development Mode
        if (result.dev_otp) {
            document.getElementById('otp').value = result.dev_otp;
            showAlert('alert-box', `<strong>DEV MODE:</strong> Code auto-filled: ${result.dev_otp}`, 'info');
        } else {
            showAlert('alert-box', result.message || 'Verification code sent to your email!', 'success');
        }
    } else {
        showAlert('alert-box', result.error || 'Could not send OTP.', 'error');
    }
}

/**
 * ── REGISTER STEP 2: Verify OTP ──
 * Validates the 6-digit code to finalize account creation.
 */
async function handleVerifyOtp(e) {
    e.preventDefault();
    clearAlert('alert-box');

    const email = document.getElementById('email').value.trim();
    const otp = document.getElementById('otp').value.replace(/\s/g, ''); // Clean whitespace

    if (otp.length !== 6) {
        showAlert('alert-box', 'Please enter a 6-digit verification code.', 'error');
        return;
    }

    setLoading('verify-btn', true, 'Verifying...');

    const result = await apiPost('/auth.php?action=verify-otp', { email, otp });

    setLoading('verify-btn', false);

    if (result.success) {
        showAlert('alert-box', 'Account created successfully! Redirecting to login...', 'success');
        setTimeout(() => {
            window.location.href = BASE + '/auth/login.html';
        }, 2000);
    } else {
        showAlert('alert-box', result.error || 'OTP verification failed.', 'error');
    }
}

/**
 * ── FORGOT PASSWORD ──
 */
async function handleForgotPassword(e) {
    e.preventDefault();
    clearAlert('alert-box');

    const email = document.getElementById('email').value.trim();
    const newPass = document.getElementById('newpass').value;
    const confirm = document.getElementById('confirm').value;

    if (newPass !== confirm) { 
        showAlert('alert-box', 'Passwords do not match.', 'error'); 
        return; 
    }

    setLoading('reset-btn', true, 'Updating Password...');

    const result = await apiPost('/password.php?action=reset', {
        email: email,
        new_password: newPass
    });

    setLoading('reset-btn', false);

    if (result.success) {
        showAlert('alert-box', 'Password reset successful! Redirecting...', 'success');
        setTimeout(() => {
            window.location.href = BASE + '/auth/login.html';
        }, 1500);
    } else {
        showAlert('alert-box', result.error || 'Reset failed. Please check your email.', 'error');
    }
}