#!/bin/bash

package="iRony"

chwala_dir="../kolab-chwala.git/"
roundcube_dir="../roundcubemail.git/"
roundcube_plugins_dir="../roundcubemail-plugins-kolab.git/"

if [ $# -ne 1 ]; then
    echo "Usage: $0 <version>"
    exit 1
fi

version=$1

if [ ! -z "$(git tag -l | grep -E '${package}-${version}$')" ]; then
    echo "Version ${version} already exists"
    exit 1
fi

if [ ! -d "${chwala_dir}/lib" ]; then
    echo "No directory ${chwala_dir}/lib/"
    exit 1
fi

if [ ! -d "${roundcube_dir}/program/lib/Roundcube/" ]; then
    echo "No directory ${roundcube_dir}/program/lib/Roundcube/"
    exit 1
fi

if [ ! -d "${roundcube_plugins_dir}/plugins/" ]; then
    echo "No directory ${roundcube_plugins_dir}/plugins/"
    exit 1
fi

if [ -f "./composer.phar" ]; then
    git clean -d -f -x
    rm -rf vendor/
fi

cp -a ${chwala_dir}/lib lib/FileAPI

pushd lib/FileAPI/ext/
rm -rf \
    Auth/ \
    HTTP/ \
    Mail/ \
    Net/  \
    PEAR5.php \
    PEAR.php \
    Roundcube/

popd

cp -a ${roundcube_dir}/program/lib/Roundcube/ lib/Roundcube
cp -a ${roundcube_plugins_dir}/plugins/ lib/plugins

curl -sS https://getcomposer.org/installer | php

if [ $? -ne 0 ]; then
    echo "Getting composer failed... Bye!"
    exit 1
fi

./composer.phar install

if [ $? -ne 0 ]; then
    echo "Running ./composer.phar install failed... Bye!"
    exit 1
fi

if [ -d "../${package}-${version}/" ]; then
    rm -rf ../${package}-${version}/
fi

mkdir -p ../${package}-${version}/
cp -a * ../${package}-${version}/.
find ../${package}-${version}/ -type d -name ".git" -exec rm -rf {} \; 2>/dev/null

pwd=$(pwd)
pushd ..
tar czvf ${pwd}/${package}-${version}+dep.tar.gz ${package}-${version}/
popd
git archive --prefix=${package}-${version}/ HEAD | gzip -c > ${package}-${version}.tar.gz
