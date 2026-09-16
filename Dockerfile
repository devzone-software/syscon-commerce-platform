FROM maven:3.9.11-eclipse-temurin-21 AS build
WORKDIR /build
COPY pom.xml .
COPY common ./common
COPY auth-service ./auth-service
COPY catalog-service ./catalog-service
COPY supplier-service ./supplier-service
COPY order-service ./order-service
COPY payment-service ./payment-service
COPY invoice-service ./invoice-service
ARG SERVICE
RUN --mount=type=cache,target=/root/.m2 mvn -B -pl ${SERVICE} -am -DskipTests package

FROM eclipse-temurin:21-jre
RUN useradd -r -u 10001 app
WORKDIR /app
ARG SERVICE
COPY --from=build /build/${SERVICE}/target/${SERVICE}-0.1.0-SNAPSHOT.jar app.jar
USER app
ENTRYPOINT ["java", "-jar", "/app/app.jar"]
