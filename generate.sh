#!/bin/bash

ghc --make site.hs
./site rebuild
mkdir -p _site/css
cp CNAME _site/CNAME
cp css_extra/* _site/css/
cp -R cv _site/cv
cp -R _site/* ../drew.kerrigan.io_site/
./site watch
