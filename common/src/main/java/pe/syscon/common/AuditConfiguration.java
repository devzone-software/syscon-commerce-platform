package pe.syscon.common;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.context.annotation.Configuration;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.web.servlet.HandlerInterceptor;
import org.springframework.web.servlet.config.annotation.InterceptorRegistry;
import org.springframework.web.servlet.config.annotation.WebMvcConfigurer;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;

@Configuration
public class AuditConfiguration implements WebMvcConfigurer {
    private static final Logger log=LoggerFactory.getLogger(AuditConfiguration.class);
    @Override public void addInterceptors(InterceptorRegistry registry) {
        registry.addInterceptor(new HandlerInterceptor() {
            @Override public void afterCompletion(HttpServletRequest request,HttpServletResponse response,Object handler,Exception exception) {
                if(!request.getRequestURI().startsWith("/api/")) return;
                var auth=SecurityContextHolder.getContext().getAuthentication();
                String actor=auth==null||!auth.isAuthenticated()?"anonymous":auth.getName();
                log.info("audit actor={} method={} path={} status={}",actor,request.getMethod(),request.getRequestURI(),response.getStatus());
            }
        });
    }
}
