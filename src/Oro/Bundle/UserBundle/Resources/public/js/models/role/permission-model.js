define(function(require) {
    'use strict';

    var PermissionModel;
    var BaseModel = require('oroui/js/app/models/base/model');

    PermissionModel = BaseModel.extend({
        _initialAccessLevel: null,

        /**
         * @inheritDoc
         */
        constructor: function PermissionModel() {
            PermissionModel.__super__.constructor.apply(this, arguments);
        },

        /**
         * @inheritDoc
         */
        initialize: function() {
            this._initialAccessLevel = this.get('access_level');
            PermissionModel.__super__.initialize.apply(this, arguments);
        },

        isAccessLevelChanged: function() {
            return this._initialAccessLevel !== this.get('access_level');
        }
    });

    return PermissionModel;
});
