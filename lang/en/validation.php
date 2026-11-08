<?php

/*
|--------------------------------------------------------------------------
| Validation wording
|--------------------------------------------------------------------------
| Laravel's own file is merged underneath this one, so only what differs is written here. People never see a field's name in code
| ("The title field is required"): every field a form can send has a plain name under `attributes`, and the messages people meet
| most (a missing value, a taken or wrong email, a weak or mismatched password, a wrong code) say what to do. A sweep test
| (tests/Feature/R42/ValidationWordingTest.php) submits every form empty and fails if any message still reads like the framework.
*/

return [

    // The general shape of the framework's messages, kept human when a rule has no message of its own below.
    'required' => 'Enter the :attribute.',
    'accepted' => 'Accept the :attribute to go on.',
    'email' => 'Enter a valid email address.',
    'confirmed' => 'The two :attribute entries do not match.',
    'unique' => 'That :attribute is already in use.',
    'exists' => 'Choose a valid :attribute.',
    'current_password' => 'That is not your current password.',
    'string' => 'Enter the :attribute as text.',
    'array' => 'Choose the :attribute from the list.',
    'integer' => 'Enter the :attribute as a whole number.',
    'max' => [
        'string' => 'The :attribute can be at most :max characters.',
        'array' => 'Choose at most :max for the :attribute.',
        'file' => 'The :attribute can be at most :max kilobytes.',
        'numeric' => 'The :attribute can be at most :max.',
    ],
    'min' => [
        'string' => 'The :attribute needs at least :min characters.',
        'array' => 'Choose at least :min for the :attribute.',
        'file' => 'The :attribute must be at least :min kilobytes.',
        'numeric' => 'The :attribute must be at least :min.',
    ],
    'password' => [
        'letters' => 'The :attribute needs at least one letter.',
        'mixed' => 'The :attribute needs at least one capital and one small letter.',
        'numbers' => 'The :attribute needs at least one number.',
        'symbols' => 'The :attribute needs at least one symbol.',
        'uncompromised' => 'That :attribute has appeared in a data leak. Choose a different one.',
    ],

    'custom' => [
        'title' => ['required' => 'Enter a title for the lot.', 'max' => 'The title can be at most :max characters.'],
        'description' => ['max' => 'The description can be at most :max characters.'],
        'category_id' => ['required' => 'Choose a category.', 'exists' => 'Choose a category from the list.', 'integer' => 'Choose a category from the list.'],
        'starting_price' => ['required' => 'Enter the starting price.'],
        'bid_increment' => ['required' => 'Enter the bid increment, the smallest step a bid can rise by.'],
        'ends_at' => ['required' => 'Enter when the lot closes.'],
        'images' => ['required' => 'Choose at least one photo to upload.'],
        'name' => ['required' => 'Enter a name.', 'max' => 'The name can be at most :max characters.'],
        'email' => [
            'required' => 'Enter your email address.',
            'email' => 'Enter a valid email address, like name@example.com.',
            'unique' => 'That email address is already in use.',
            'exists' => 'No one with that email address has an account here.',
        ],
        'password' => ['required' => 'Enter a password.', 'confirmed' => 'The two passwords do not match.'],
        'current_password' => ['required' => 'Enter your current password.', 'current_password' => 'That is not your current password.'],
        'terms' => ['accepted' => 'Accept the terms and privacy policy to create an account.', 'required' => 'Accept the terms and privacy policy to create an account.'],
        'code' => ['required' => 'Enter the code from your authenticator app.'],
        'recovery_code' => ['required' => 'Enter one of your recovery codes.'],
        'role' => ['required' => 'Choose a role.'],
        'token' => ['required' => 'This link is not valid any more. Ask for a new one.'],
        'bidAmount' => ['required' => 'Enter the amount you want to bid.'],
    ],

    // What a field is called when a message has to name it.
    'attributes' => [
        'title' => 'title',
        'description' => 'description',
        'category_id' => 'category',
        'starting_price' => 'starting price',
        'reserve_price' => 'reserve price',
        'buy_now_price' => 'buy now price',
        'bid_increment' => 'bid increment',
        'starts_at' => 'opening time',
        'ends_at' => 'closing time',
        'images' => 'photos',
        'images.*' => 'photo',
        'name' => 'name',
        'email' => 'email address',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'current_password' => 'current password',
        'terms' => 'terms',
        'code' => 'code',
        'recovery_code' => 'recovery code',
        'role' => 'role',
        'token' => 'link',
        'bidAmount' => 'bid',
        'state.name' => 'name',
        'state.email' => 'email address',
        'addTeamMemberForm.email' => 'email address',
        'addTeamMemberForm.role' => 'role',
    ],

];
