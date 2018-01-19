var ghpages = require('gh-pages');

ghpages.publish('page', function (err) {
    console.log(err);
});