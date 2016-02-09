var _AUTH_USERSEARCH;
_AUTH_USERSEARCH = function()
{
    var base = this;

    // --------------------------------------------------------------------------

    base.__construct =function() {

        $('input.user-search').select2({
            placeholder: "Search for a user",
            minimumInputLength: 3,
            ajax: {
                url: window.SITE_URL + 'api/auth/user/search',
                dataType: 'json',
                quietMillis: 250,
                data: function (term) {
                    return {
                        keywords: term
                    };
                },
                results: function (data) {
                    var out = {
                        'results': []
                    };

                    for (var key in data.data) {
                        if (data.data.hasOwnProperty(key)) {
                            out.results.push({
                                'id': data.data[key].id,
                                'text': data.data[key].name + ' (' + data.data[key].email + ')'
                            });
                        }
                    }

                    return out;
                },
                cache: true
            },
            initSelection: function(element, callback) {

                var id = $(element).val();

                if (id !== '') {

                    $.ajax({
                        url: window.SITE_URL + 'api/auth/user',
                        data: {
                            'id': id
                        },
                        dataType: 'json'
                    }).done(function(data) {

                        var out = {
                            'id': data.user.id,
                            'text': data.user.first_name + ' ' + data.user.last_name + ' (' + data.user.email + ')'
                        };

                        callback(out);
                    });
                }
            }
        });
    };

    // --------------------------------------------------------------------------

    return base.__construct();
}();