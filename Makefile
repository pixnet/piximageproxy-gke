PROJECT_NAME= imageproxy.pimg.tw
PROJECT_ID=	pixnet-data
IMAGE_NAME=	$(PROJECT_NAME)
GKE_CLUSTER_ID=	imageproxy
GKE_CLUSTER_REGION=	asia-east1-b
GCLOUD=		gcloud
KUBECTL=	kubectl
CURRENT_DIR := $(shell pwd)
CURRENT_CLUSTER := $(shell $(KUBECTL) config current-context)
STR_CLUSTER := gke_$(PROJECT_ID)_$(GKE_CLUSTER_REGION)_$(GKE_CLUSTER_ID)
CURRENT_HASH := $(shell git rev-parse --verify HEAD)

all:
	docker pull pixnet/nginx-php7-fpm --all-tags
	git pull --rebase -v -p
	(cd $(PROJECT_NAME);composer install --ignore-platform-reqs)

pre-deploy:
	git pull -v
	git push -v

build:
	docker build -t $(PROJECT_NAME) .

run:
	docker run -i -p 8080:8082 -t -v ${CURRENT_DIR}/$(PROJECT_NAME):/pixnet/$(PROJECT_NAME) \
	$(PROJECT_NAME)

exec:
	docker exec -it `docker ps -q` bash

stop:
	docker ps -q | xargs docker kill

deploy:check-cluster pre-deploy docker-build docker-push rolling-update

deploy-instant:check-cluster pre-deploy docker-build docker-push instant-update

check-cluster:
ifneq ($(CURRENT_CLUSTER),$(STR_CLUSTER))
	$(error cluster $(CURRENT_CLUSTER) context mismatch with Makefile $(STR_CLUSTER)! please run `make gke-connect-cluster` first)
endif

clean:

gcloud-login:
	$(GCLOUD) auth login

gcloud-project: gcloud-login
	$(GCLOUD) config set project $(PROJECT_ID)

gke-connect-cluster: gcloud-project
	$(GCLOUD) container clusters get-credentials $(GKE_CLUSTER_ID) --zone $(GKE_CLUSTER_REGION) --project $(PROJECT_ID)

gke-proxy-cluster: gke-connect-cluster
	$(KUBECTL) proxy

docker-build:
	$(GCLOUD) docker -- build -t asia.gcr.io/$(PROJECT_ID)/$(IMAGE_NAME) .

docker-push:
	$(GCLOUD) docker -- push asia.gcr.io/$(PROJECT_ID)/$(IMAGE_NAME)

rolling-update:
	$(KUBECTL) rolling-update $(GKE_CLUSTER_ID) --image=asia.gcr.io/$(PROJECT_ID)/$(IMAGE_NAME):latest --image-pull-policy=Always

rolling-update-git-hash:
	@echo current git commit: $(CURRENT_HASH)
	$(KUBECTL) rolling-update $(GKE_CLUSTER_ID) --image=asia.gcr.io/$(PROJECT_ID)/$(IMAGE_NAME):$(CURRENT_HASH) --image-pull-policy=Always
	
instant-update:
	-$(KUBECTL) delete rc $(GKE_CLUSTER_ID)
	-$(KUBECTL) create -f conf/gke-rc.yaml

