#!/bin/bash

ghc --make site.hs
mkdir -p _site/css
cp CNAME _site/CNAME
cp css_extra/* _site/css/
cp -R cv _site/cv
./site watch
