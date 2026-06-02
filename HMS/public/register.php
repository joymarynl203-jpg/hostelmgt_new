<?php

require_once __DIR__ . '/../lib/layout.php';

require_once __DIR__ . '/../lib/auth.php';

require_once __DIR__ . '/../lib/csrf.php';

require_once __DIR__ . '/../lib/helpers.php';



if (hms_current_user()) {

    redirect_to(hms_url('dashboard.php'));

}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify($_POST['csrf_token'] ?? '');



    $name = trim((string)($_POST['name'] ?? ''));

    $email = trim((string)($_POST['email'] ?? ''));

    $institution = trim((string)($_POST['institution'] ?? ''));

    $regNo = trim((string)($_POST['reg_no'] ?? ''));

    $phone = trim((string)($_POST['phone'] ?? ''));

    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    $pwCheck = hms_passwords_match($password, $passwordConfirm);
    if (!$pwCheck['ok']) {
        flash_set('error', $pwCheck['error'] ?? 'Passwords do not match.');
    } elseif ($institution === '' || mb_strlen($institution) < 2) {

        flash_set('error', 'Please enter your institution or university name.');

    } elseif (mb_strlen($institution) > 200) {

        flash_set('error', 'Institution name is too long (maximum 200 characters).');

    } elseif ($phone === '') {

        flash_set('error', 'Telephone number is required.');

    } elseif (mb_strlen($phone) < 7) {

        flash_set('error', 'Please enter a valid telephone number (at least 7 characters).');

    } elseif (mb_strlen($phone) > 30) {

        flash_set('error', 'Telephone number is too long.');

    } elseif (!empty($regNo) && mb_strlen($regNo) < 3) {

        flash_set('error', 'Registration number is too short.');

    } else {

        if (auth_register_student($name, $email, $password, $institution, $phone, $regNo !== '' ? $regNo : null)) {

            flash_set('success', 'Account created. Please log in.');

            redirect_to(hms_url('login.php'));

        }

        flash_set('error', 'Unable to register. Check your details (email might already exist) and password length (min 8 chars).');

    }

}



layout_header('Register (Student)', ['body_class' => 'hms-page-login-bg']);

?>



<div class="row justify-content-center">

    <div class="col-md-7 col-lg-5">

        <div class="card shadow-sm border-0 rounded-4 hms-auth-card">

            <div class="card-body p-4">

                <h2 class="h4 mb-3">Create student account</h2>

                <form method="post" action=""<?php echo hms_data_confirm('Create this student account with the details you entered?'); ?>>

                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">



                    <div class="mb-3">

                        <label class="form-label">Full Name</label>

                        <input type="text" name="name" class="form-control" required autocomplete="name">

                    </div>



                    <div class="mb-3">

                        <label class="form-label">Institution / University <span class="text-danger">*</span></label>

                        <input type="text" name="institution" class="form-control" required maxlength="200" placeholder="e.g. Makerere University" autocomplete="organization">

                    </div>



                    <div class="mb-3">

                        <label class="form-label">Registration Number (optional)</label>

                        <input type="text" name="reg_no" class="form-control" placeholder="e.g. 23/2/306/D/092">

                    </div>



                    <div class="mb-3">

                        <label class="form-label">Telephone <span class="text-danger">*</span></label>

                        <input type="tel" name="phone" class="form-control" required minlength="7" maxlength="30" placeholder="e.g. 07XXXXXXXX" autocomplete="tel">

                    </div>



                    <div class="mb-3">

                        <label class="form-label">Email</label>

                        <input type="email" name="email" class="form-control" required autocomplete="email">

                    </div>



                    <?php
                        hms_render_password_field('password', 'Password', [
                            'required' => true,
                            'minlength' => 8,
                            'autocomplete' => 'new-password',
                            'help' => 'Minimum 8 characters.',
                        ]);
                        hms_render_password_field('password_confirm', 'Confirm password', [
                            'required' => true,
                            'minlength' => 8,
                            'autocomplete' => 'new-password',
                        ]);
                    ?>



                    <button class="btn btn-primary w-100" type="submit">Register</button>

                </form>



                <div class="mt-3 text-center small text-muted">

                    Already registered? <a href="<?php echo hms_url('login.php'); ?>">Login</a>

                </div>

            </div>

        </div>

    </div>

</div>



<?php layout_footer(); ?>



