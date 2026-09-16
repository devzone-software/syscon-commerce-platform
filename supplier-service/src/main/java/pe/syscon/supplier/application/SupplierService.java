package pe.syscon.supplier.application;

import java.math.BigDecimal;
import java.net.URI;
import java.net.http.*;
import java.time.*;
import java.util.*;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;
import org.springframework.scheduling.annotation.Scheduled;
import pe.syscon.common.ApiException;
import pe.syscon.supplier.domain.*;
import pe.syscon.supplier.infrastructure.*;

@Service
public class SupplierService {
 private final SupplierRepository suppliers;private final SupplierProductRepository products;private final ScrapingJobRepository jobs;private final ScrapingHistoryRepository history;
 private final CatalogClient catalog;private final String serviceToken;private final Set<String> allowedHosts;
 private final HttpClient http=HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(10)).followRedirects(HttpClient.Redirect.NEVER).build();
 public SupplierService(SupplierRepository suppliers,SupplierProductRepository products,ScrapingJobRepository jobs,ScrapingHistoryRepository history,CatalogClient catalog,@Value("${security.service-token}") String serviceToken,@Value("${supplier.allowed-hosts:}") String allowedHosts) {
  this.suppliers=suppliers;this.products=products;this.jobs=jobs;this.history=history;this.catalog=catalog;this.serviceToken=serviceToken;
  this.allowedHosts=Arrays.stream(allowedHosts.split(",")).map(String::strip).map(x -> x.toLowerCase(Locale.ROOT)).filter(x -> !x.isBlank()).collect(java.util.stream.Collectors.toSet());
 }
 public Supplier add(String name,String feedUrl,int minIntervalMinutes) {
  if(minIntervalMinutes<15) throw new ApiException(HttpStatus.BAD_REQUEST,"Intervalo mínimo: 15 minutos");
  validateUri(feedUrl);return suppliers.save(new Supplier(name,feedUrl,minIntervalMinutes));
 }
 public List<Supplier> list() {return suppliers.findAll();}
 public List<ScrapingJob> jobs() {return jobs.findAll();}
 public List<ScrapingHistory> history(UUID jobId) {return history.findByJobIdOrderByOccurredAtAsc(jobId);}
 @Scheduled(fixedDelayString="${supplier.poll-ms:900000}")
 public void scheduled() {
  for(var supplier:suppliers.findAll()) if(supplier.active) {
   try {run(supplier.id);} catch(ApiException e) {if(e.status()!=HttpStatus.TOO_MANY_REQUESTS) org.slf4j.LoggerFactory.getLogger(getClass()).warn("Supplier {}: {}",supplier.id,e.getMessage());}
  }
 }
 public ScrapingJob run(UUID supplierId) {
  Supplier s=suppliers.findById(supplierId).orElseThrow(() -> new ApiException(HttpStatus.NOT_FOUND,"Proveedor no encontrado"));
  if(!s.active) throw new ApiException(HttpStatus.CONFLICT,"Proveedor inactivo");
  var last=jobs.findTopBySupplierIdOrderByStartedAtDesc(s.id);
  if(last.isPresent() && last.get().startedAt.plus(Duration.ofMinutes(s.minIntervalMinutes)).isAfter(Instant.now())) throw new ApiException(HttpStatus.TOO_MANY_REQUESTS,"Frecuencia excedida");
  var job=jobs.save(new ScrapingJob(s));
  try {
   var uri=validateUri(s.feedUrl);
   var request=HttpRequest.newBuilder(uri).timeout(Duration.ofSeconds(20)).header("Accept","text/csv").GET().build();
   HttpResponse<String> response=null;
   for(int attempt=1;attempt<=3;attempt++) {
    try {response=http.send(request,HttpResponse.BodyHandlers.ofString());if(response.statusCode()==200) break;}
    catch(Exception e) {history.save(new ScrapingHistory(job,"WARN","Intento "+attempt+" falló: "+e.getClass().getSimpleName()));}
    if(attempt<3) Thread.sleep(attempt*1000L);
   }
   if(response==null || response.statusCode()!=200) throw new IllegalStateException("Feed no disponible");
   if(response.body().length()>5_000_000) throw new IllegalArgumentException("Feed demasiado grande");
   var lines=response.body().lines().toList();
   if(lines.isEmpty() || !lines.getFirst().equalsIgnoreCase("sku;name;price;stock;imageUrl;brand;category")) throw new IllegalArgumentException("Cabecera CSV inválida");
   for(String line:lines.subList(1,lines.size())) {
    if(line.isBlank()) continue;
    String[] c=line.split(";",-1);if(c.length!=7) throw new IllegalArgumentException("Fila CSV inválida");
    String sku=c[0].strip(),name=c[1].strip();BigDecimal price=new BigDecimal(c[2].strip());int stock=Integer.parseInt(c[3].strip());
    if(sku.isBlank()||name.isBlank()||price.signum()<0||stock<0) throw new IllegalArgumentException("Producto inválido");
    catalog.upsert(serviceToken,new CatalogClient.ProductInput(sku,name,price,stock,c[4].strip(),c[6].strip(),c[5].strip(),true));
    SupplierProduct p=products.findBySupplierIdAndSku(s.id,sku).orElseGet(() -> new SupplierProduct(s,sku));
    p.name=name;p.price=price;p.stock=stock;p.imageUrl=c[4].strip();p.brand=c[5].strip();p.category=c[6].strip();products.save(p);job.imported++;
   }
   job.status="SUCCEEDED";history.save(new ScrapingHistory(job,"INFO","Importados: "+job.imported));
  } catch(Exception e) {job.status="FAILED";history.save(new ScrapingHistory(job,"ERROR",e.getMessage()==null?e.getClass().getSimpleName():e.getMessage()));}
  job.finishedAt=Instant.now();return jobs.save(job);
 }
 private URI validateUri(String url) {
  URI uri=URI.create(url);String host=uri.getHost();
  if(!"https".equalsIgnoreCase(uri.getScheme())||host==null||!allowedHosts.contains(host)) throw new ApiException(HttpStatus.BAD_REQUEST,"Host HTTPS no permitido para proveedor");
  return uri;
 }
}
