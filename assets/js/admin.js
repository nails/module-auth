'use strict';

import '../sass/admin.scss';
import AccountCreate from './components/AccountCreate.js';
import AccountEdit from './components/AccountEdit.js';
import AccountMerge from './components/AccountMerge.js';
import Groups from './components/Groups.js';
import SearchUser from './components/SearchUser.js';
import UserImport from './components/UserImport.js';

(function() {
    window.NAILS.ADMIN.registerPlugin(
        'nails/module-auth',
        'AccountCreate',
        new AccountCreate()
    );
    window.NAILS.ADMIN.registerPlugin(
        'nails/module-auth',
        'AccountEdit',
        new AccountEdit()
    );
    window.NAILS.ADMIN.registerPlugin(
        'nails/module-auth',
        'AccountMerge',
        new AccountMerge()
    );
    window.NAILS.ADMIN.registerPlugin(
        'nails/module-auth',
        'Groups',
        new Groups()
    );
    window.NAILS.ADMIN.registerPlugin(
        'nails/module-auth',
        'SearchUser',
        new SearchUser(window.NAILS.ADMIN)
    );
    window.NAILS.ADMIN.registerPlugin(
        'nails/module-auth',
        'UserImport',
        new UserImport(window.NAILS.ADMIN)
    );
})();

