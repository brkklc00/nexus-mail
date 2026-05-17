"use strict";


var gulp = require("gulp"),
    newer = require("gulp-newer"),
    imagemin = require("gulp-imagemin"),
    sass = require("gulp-sass")(require('sass')),
    sourcemaps = require("gulp-sourcemaps"),
    autoprefixer = require("gulp-autoprefixer"),
    cleanCSS = require("gulp-clean-css"),
    rename = require("gulp-rename"),
    concat = require("gulp-concat"),
    uglify = require("gulp-uglify"),
    rtlcss = require('gulp-rtlcss'),
    lodash = require("lodash"),
    browsersync = require("browser-sync"),
    fileinclude = require('gulp-file-include');

var folder = {
    src: "src/", // source files
    dist: "dist/", // build files
    dist_assets: "dist/assets/" //build assets files
};

/*
Copy assets/vendors from their node_module package to scss & js folder
Read More: https://florian.ec/articles/frontend-dependencies-npm/
*/

function copyAssets(done) {
    var assets = {
        js: [
            folder.src + "js/jquery.min.js",
            folder.src + "js/bootstrap.bundle.min.js",
            folder.src + "js/metismenu.min.js",
            folder.src + "js/waves.js",
            folder.src + "js/feather.min.js",
            folder.src + "js/moment.js",
            folder.src + "js/simplebar.min.js",
        
        ],
        scss: [
        ],
        css: [
            folder.src + "scss/plugins/metisMenu.min.css"
        ]
    };

    var pageAssets = {
        js: [
            folder.src + "pages/*.js",
            //folder.src + "js/vendor/feather.min.js",
        
        ],
        scss: [
        ],
    };


    // var pluginsAssets = {
    //     js: [
    //         folder.src + "plugins/daterangepicker/daterangepicker.js",
    //         //folder.src + "js/vendor/feather.min.js",
        
    //     ],
    //     scss: [
    //     ],
    // };





   

    //copying required assets
    lodash(assets).forEach(function (assets, type) {
        if (assets && assets.length > 0) {
            if (type == "scss") {
                gulp
                    .src(assets)
                    .pipe(
                        rename({
                            // rename aaa.css to _aaa.scss
                            prefix: "_",
                            extname: ".scss"
                        })
                    )
                    .pipe(gulp.dest(folder.dist_assets + "scss"));
            } else if(type=='css'){
                gulp.src(assets).pipe(gulp.dest(folder.dist_assets + "css"));
            } else {
                gulp.src(assets).pipe(gulp.dest(folder.dist_assets + "js"));
            }
        }
    });

     //copying required assets
     lodash(pageAssets).forEach(function (assets, type) {
        if (assets && assets.length > 0) {
            if (type == "scss") {
                gulp
                    .src(assets)
                    .pipe(
                        rename({
                            // rename aaa.css to _aaa.scss
                            prefix: "_",
                            extname: ".scss"
                        })
                    )
                    .pipe(gulp.dest(folder.dist_assets + "scss/pages"));
            } else {
                gulp.src(assets).pipe(gulp.dest(folder.dist_assets + "pages"));
            }
        }
    });

     gulp.src([
        folder.src + "plugins/**",
    ])
    .pipe(gulp.dest(folder.dist_assets + "plugins/"));


     //copying required assets
    //  lodash(pluginsAssets).forEach(function (assets, type) {
    //     if (assets && assets.length > 0) {
    //         if (type == "scss") {
    //             gulp
    //                 .src(assets)
    //                 .pipe(
    //                     rename({
    //                         // rename aaa.css to _aaa.scss
    //                         prefix: "_",
    //                         extname: ".scss"
    //                     })
    //                 )
    //                 .pipe(gulp.dest(folder.dist_assets + "scss/plugins"));
    //         } else {
    //             gulp.src(assets).pipe(gulp.dest(folder.dist_assets + "plugins"));
    //         }
    //     }
    // });



    done();
}

// image processing
function imageMin() {
    var out = folder.dist_assets + "images";
    return gulp
        .src(folder.src + "images/**/*")
        .pipe(newer(out))
        .pipe(imagemin())
        .pipe(gulp.dest(out));
}


// copy fonts from src folder to dist folder
function fonts() {
    var out = folder.dist_assets + "fonts/";
    return gulp.src([folder.src + "fonts/**/*"]).pipe(gulp.dest(out));
}

// copy html files from src folder to dist folder, also copy favicons
function html() {
    var out = folder.dist;

    return gulp
        .src([
            folder.src + "html/*.html",
            folder.src + "html/*.ico", // favicons
            folder.src + "html/*.png",
            folder.src + "html/**",
        ])
        .pipe(fileinclude({
            prefix: '@@',
            basepath: '@file',
            indent: true
        }))
        .pipe(gulp.dest(out));
}

// compile & minify sass
function css() {
    gulp
        .src([
            folder.src + "scss/app.scss",
            folder.src + "scss/bootstrap.scss",
            folder.src + "scss/icons.scss",
            folder.src + "scss/app-dark.scss",
            folder.src + "scss/bootstrap-dark.scss"
        ])
        .pipe(sourcemaps.init())
        .pipe(sass()) // scss to css
        .pipe(
            autoprefixer({
                overrideBrowserslist: ["last 2 versions"]
            })
        )
        .pipe(gulp.dest(folder.dist_assets + "css/"))
        .pipe(cleanCSS())
        .pipe(
            rename({
                // rename app.css to app.min.css
                suffix: ".min"
            })
        )
        .pipe(sourcemaps.write("./")) // source maps for app-dark.min.css
        .pipe(gulp.dest(folder.dist_assets + "css/"));


    return gulp
    .src([
        folder.src + "scss/app.scss",
        folder.src + "scss/bootstrap.scss",
        folder.src + "scss/app-dark.scss",
        folder.src + "scss/bootstrap-dark.scss"
    ])
    .pipe(sourcemaps.init())
    .pipe(sass()) // scss to css
    .pipe(
        autoprefixer({
            overrideBrowserslist: ["last 2 versions"]
        })
    )
    .pipe(gulp.dest(folder.dist_assets + "css/"))
    .pipe(rtlcss())
    .pipe(cleanCSS())
    .pipe(
        rename({
            // rename app.css to app.min.css
            suffix: "-rtl.min"
        })
    )
    .pipe(sourcemaps.write("./")) // source maps for app-dark.min.css
    .pipe(gulp.dest(folder.dist_assets + "css/"));

}

// js
function javascript() {
    var out = folder.dist_assets + "js/";

    //copying demo pages related assets
    var app_pages_assets = {
        js: []
    };

    lodash(app_pages_assets).forEach(function (assets, type) {
        if (assets && assets.length > 0) {
            gulp.src(assets)
                .pipe(uglify())
                .on("error", function (err) {
                    console.log(err.toString());
                })
                .pipe(gulp.dest(out + "pages"));
        }
    });

    // optional components
    var components_assets = {
        js: []
    };

    lodash(components_assets).forEach(function (assets, type) {
        if (assets && assets.length > 0) {
            gulp.src(assets)
                .pipe(uglify())
                .on("error", function (err) {
                    console.log(err.toString());
                })
                .pipe(gulp.dest(out + "ui"));
        }
    });

    gulp
        .src([
            folder.src + "js/jquery.min.js",
            folder.src + "js/bootstrap.bundle.min.js",
            folder.src + "js/metismenu.min.js",
            folder.src + "js/simplebar.min.js",
            folder.src + "js/moment.js",
            folder.src + "js/waves.js",
            folder.src + "js/feather.min.js"
        ])
        .pipe(sourcemaps.init())
        .pipe(concat("vendor.js"))
        .pipe(gulp.dest(out))
        .pipe(
            rename({
                // rename app.js to app.min.js
                suffix: ".min"
            })
        )
        // .pipe(uglify())
        .on("error", function (err) {
            console.log(err.toString());
        })
        .pipe(sourcemaps.write("./"))
        .pipe(gulp.dest(out));

    // It's important to keep files at this order
    // so that `app.min.js` can be executed properly
    return gulp
        .src([
            folder.src + "js/app.js"
        ])
        .pipe(sourcemaps.init())
        .pipe(concat("app.js"))
        .pipe(gulp.dest(out))
        .pipe(
            rename({
                // rename app.js to app.min.js
                suffix: ".min"
            })
        )
        // .pipe(uglify())
        .on("error", function (err) {
            console.log(err.toString());
        })
        .pipe(sourcemaps.write("./"))
        .pipe(gulp.dest(out));
}

// live browser loading
function browserSync(done) {
    browsersync.init({
        server: {
            baseDir: folder.dist,
            middleware: [
                function (req, res, next) {
                    req.method = 'GET';
                    next();
                }
            ]
        }
    });
    done();
}

function reloadBrowserSync(done) {
    browsersync.reload();
    done();
}

function watchFiles() {
    gulp.watch(folder.src + "html/**", gulp.series(html, reloadBrowserSync));
    gulp.watch(folder.src + "assets/images/**/*", gulp.series(imageMin, reloadBrowserSync));
    gulp.watch(folder.src + "assets/fonts/**/*", gulp.series(fonts, reloadBrowserSync));
    gulp.watch(folder.src + "scss/**/*", gulp.series(css, reloadBrowserSync));
    gulp.watch(folder.src + "js/**/*", gulp.series(javascript, reloadBrowserSync));
}

// watch all changes
gulp.task("watch", gulp.parallel(watchFiles, browserSync));

// default task
gulp.task(
    "default",
    gulp.series(
        copyAssets,
        html,
        imageMin,
        fonts,
        css,
        javascript,
        'watch'
    ),
    function (done) { done(); }
);

// build
gulp.task(
    "build",
    gulp.series(copyAssets,
        html,
        imageMin,
        fonts,
        css,
        javascript)
);

// doc
gulp.task("docs", function () {
    browsersync.init({
        server: {
            baseDir: "docs"
        }
    });
});
